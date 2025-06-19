<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$success_message = '';
$error_message = '';

if ($VER['REQUEST__SERMETHOD'] === 'POST') {
    error_log("--- TRANSKSI.PHP POST REQUEST START ---");
    error_log("POST data received: " . print_r($_POST, true));

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $id_sewa = trim($_POST['id_sewa']);
                $tanggal_pembayaran = isset($_POST['tanggal_pembayaran']) ? trim($_POST['tanggal_pembayaran']) : '';
                $jumlah = trim(($_POST['jumlah']));
                $metode_pembayaran = trim($_POST['metode_pembayaran']);
                $status = trim($_POST['status']);
                $tipe_pembayaran = isset($_POST['tipe_pembayaran']) ? trim($_POST['tipe_pembayaran']) : 'bulanan';

                if (empty($id_sewa) || empty($jumlah) || empty($metode_pembayaran) || empty($status) || empty($tanggal_pembayaran)) {
                    $error_message = "Semua field wajib diisi!";
                    break;
                }

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_pembayaran)) {
                    $error_message = "Format tanggal tidak valid. Gunakan date picker yang tersedia.";
                    break;
                }

                $date_parts = explode('-', $tanggal_pembayaran);
                if (!checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
                    $error_message = "Tanggal pembayaran tidak valid.";
                    break;
                }

                if (!is_numeric($jumlah) || $jumlah <= 0) {
                    $error_message = "Jumlah pembayaran harus berupa angka positif!";
                    break;
                }

                $payment_date_obj = new DateTime($tanggal_pembayaran);

                $blocked_by_lunas_payment = false;
                $block_reason = "";

                $payment_month = $payment_date_obj->format('Y-m');
                $check_lunas_monthly_query = "SELECT COUNT(*) FROM transaksi WHERE id_sewa = ? AND DATE_FORMAT(tanggal_pembayaran, '%Y-%m') = ? AND status = 'Lunas' AND tipe_pembayaran = 'bulanan'";
                $check_stmt_monthly = $conn->prepare($check_lunas_monthly_query);
                if ($check_stmt_monthly) {
                    $check_stmt_monthly->bind_param("ss", $id_sewa, $payment_month);
                    $check_stmt_monthly->execute();
                    $result_monthly = $check_stmt_monthly->get_result();
                    $row_monthly = $result_monthly->fetch_row();
                    $count_lunas_monthly = $row_monthly[0];
                    $check_stmt_monthly->close();

                    if ($count_lunas_monthly > 0) {
                        $blocked_by_lunas_payment = true;
                        $block_reason = "Sewa ini sudah memiliki pembayaran Bulanan Lunas untuk bulan " . $payment_date_obj->format('F Y') . ". ";
                        error_log("Blocked by existing monthly Lunas payment for id_sewa: " . $id_sewa . " in month " . $payment_month);
                    }
                } else {
                    $error_message = "Gagal menyiapkan pengecekan bulanan: " . mysqli_error($conn);
                    error_log("Failed to prepare monthly check query: " . mysqli_error($conn));
                    break;
                }

                if (!$blocked_by_lunas_payment) {
                    $check_lunas_yearly_query = "SELECT tanggal_pembayaran FROM transaksi WHERE id_sewa = ? AND status = 'Lunas' AND tipe_pembayaran = 'tahunan'";
                    $check_stmt_yearly = $conn->prepare($check_lunas_yearly_query);
                    if ($check_stmt_yearly) {
                        $check_stmt_yearly->bind_param("s", $id_sewa);
                        $check_stmt_yearly->execute();
                        $result_yearly = $check_stmt_yearly->get_result();

                        while ($yearly_payment = $result_yearly->fetch_assoc()) {
                            $yearly_start_date = new DateTime($yearly_payment['tanggal_pembayaran']);
                            $yearly_end_date = (clone $yearly_start_date)->modify('+1 year -1 day');

                            if ($payment_date_obj >= $yearly_start_date && $payment_date_obj <= $yearly_end_date) {
                                $blocked_by_lunas_payment = true;
                                $block_reason = "Sewa ini sudah memiliki pembayaran Tahunan Lunas yang mencakup periode " . $yearly_start_date->format('d F Y') . " - " . $yearly_end_date->format('d F Y') . ". ";
                                error_log("Blocked by existing yearly Lunas payment for id_sewa: " . $id_sewa . " covering " . $yearly_start_date->format('Y-m-d') . " to " . $yearly_end_date->format('Y-m-d'));
                                break;
                            }
                        }
                        $check_stmt_yearly->close();
                    } else {
                        $error_message = "Gagal menyiapkan pengecekan tahunan: " . mysqli_error($conn);
                        error_log("Failed to prepare yearly check query: " . mysqli_error($conn));
                        break;
                    }
                }

                if ($blocked_by_lunas_payment) {
                    if ($status === 'Lunas') {
                        $error_message = $block_reason . "Tidak dapat menambahkan transaksi Lunas ganda.";
                        error_log("Blocking Lunas double payment: " . $error_message);
                        break;
                    } else {
                        $error_message = $block_reason . "Transaksi baru dibatalkan. Status transaksi diset menjadi Gagal.";
                        $status = 'Gagal';
                        error_log("Setting new transaction status to Gagal due to existing Lunas payment: " . $error_message);
                    }
                }

                $get_sewa_dates_query = "SELECT tanggal_mulai, tanggal_selesai FROM sewa WHERE id_sewa = ?";
                $dates_stmt = $conn->prepare($get_sewa_dates_query);
                if ($dates_stmt) {
                    $dates_stmt->bind_param("s", $id_sewa);
                    $dates_stmt->execute();
                    $result_dates = $dates_stmt->get_result();
                    $sewa_dates = $result_dates->fetch_assoc();
                    $dates_stmt->close();

                    if ($sewa_dates) {
                        $sewa_start = strtotime($sewa_dates['tanggal_mulai']);
                        $sewa_end = strtotime($sewa_dates['tanggal_selesai']);
                        $payment_date_timestamp = strtotime($tanggal_pembayaran);

                        if ($payment_date_timestamp < $sewa_start || $payment_date_timestamp > $sewa_end) {
                            $error_message = "Tanggal pembayaran (" . date('d F Y', $payment_date_timestamp) . ") harus berada dalam rentang kontrak sewa (" . date('d F Y', $sewa_start) . " - " . date('d F Y', $sewa_end) . ").";
                            error_log("Payment date outside rental contract range: " . $error_message);
                            break;
                        }
                    } else {
                        $error_message = "Data kontrak sewa tidak ditemukan untuk ID yang dipilih.";
                        error_log("Rental contract data not found for id_sewa: " . $id_sewa);
                        break;
                    }
                } else {
                    $error_message = "Gagal menyiapkan pengecekan rentang tanggal sewa: " . mysqli_error($conn);
                    error_log("Failed to prepare rental date range check query: " . mysqli_error($conn));
                    break;
                }

                $id_transaksi = generateUUID();
                $stmt = $conn->prepare("INSERT INTO transaksi (id_transaksi, id_sewa, tanggal_pembayaran, jumlah, metode_pembayaran, status, tipe_pembayaran) VALUES (?, ?, ?, ?, ?, ?, ?)");

                if ($stmt) {
                    $stmt->bind_param("sssdsss", $id_transaksi, $id_sewa, $tanggal_pembayaran, $jumlah, $metode_pembayaran, $status, $tipe_pembayaran);

                    try {
                        if ($stmt->execute()) {
                            if ($blocked_by_lunas_payment && $status === 'Gagal') {
                            } else {
                                $success_message = "Transaksi berhasil ditambahkan!";
                            }

                            if ($status === 'Lunas') {
                                $current_date = date('Y-m-d');
                                $get_sewa_status_stmt = $conn->prepare("SELECT status, id_unit FROM sewa WHERE id_sewa = ?");
                                $sewa_current_status = '';
                                $id_unit_sewa = '';
                                if ($get_sewa_status_stmt) {
                                    $get_sewa_status_stmt->bind_param("s", $id_sewa);
                                    $get_sewa_status_stmt->execute();
                                    $get_sewa_status_result = $get_sewa_status_stmt->get_result();
                                    if ($row = $get_sewa_status_result->fetch_assoc()) {
                                        $sewa_current_status = $row['status'];
                                        $id_unit_sewa = $row['id_unit'];
                                    }
                                    $get_sewa_status_stmt->close();
                                } else {
                                    error_log("Failed to prepare get sewa status: " . mysqli_error($conn));
                                }

                                if ($sewa_current_status !== 'Aktif') {
                                    $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Aktif' WHERE id_sewa = ?");
                                    if ($update_sewa_stmt) {
                                        $update_sewa_stmt->bind_param("s", $id_sewa);
                                        $update_sewa_stmt->execute();
                                        $update_sewa_stmt->close();
                                        error_log("Sewa status updated to Aktif for sewa ID: " . $id_sewa);
                                    } else {
                                        error_log("Failed to prepare update sewa status: " . mysqli_error($conn));
                                    }
                                }

                                if (!empty($id_unit_sewa)) {
                                    $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Terisi' WHERE id_unit = ?");
                                    if ($update_unit_stmt) {
                                        $update_unit_stmt->bind_param("s", $id_unit_sewa);
                                        $update_unit_stmt->execute();
                                        $update_unit_stmt->close();
                                        error_log("Unit status updated to Terisi for unit ID: " . $id_unit_sewa);
                                    } else {
                                        error_log("Failed to prepare update unit status: " . mysqli_error($conn));
                                    }
                                }
                            } else if ($status === 'Gagal') {
                                $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Dibatalkan' WHERE id_sewa = ?");
                                if ($update_sewa_stmt) {
                                    $update_sewa_stmt->bind_param("s", $id_sewa);
                                    $update_sewa_stmt->execute();
                                    $update_sewa_stmt->close();
                                    error_log("Sewa status updated to Dibatalkan (via Gagal transaction) for sewa ID: " . $id_sewa);
                                } else {
                                    error_log("Failed to prepare update sewa status (Dibatalkan): " . mysqli_error($conn));
                                }
                                if (!empty($id_unit_sewa)) {
                                    $check_other_active_rentals_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                                    if ($check_other_active_rentals_stmt) {
                                        $check_other_active_rentals_stmt->bind_param("s", $id_unit_sewa);
                                        $check_other_active_rentals_stmt->execute();
                                        $other_active_count = $check_other_active_rentals_stmt->get_result()->fetch_row()[0];
                                        $check_other_active_rentals_stmt->close();

                                        if ($other_active_count == 0) {
                                            $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                            if ($update_unit_stmt) {
                                                $update_unit_stmt->bind_param("s", $id_unit_sewa);
                                                $update_unit_stmt->execute();
                                                $update_unit_stmt->close();
                                                error_log("Unit status updated to Tersedia (via Gagal transaction) for unit ID: " . $id_unit_sewa);
                                            } else {
                                                error_log("Failed to prepare update unit status (Tersedia, Gagal transaction): " . mysqli_error($conn));
                                            }
                                        }
                                    } else {
                                        error_log("Failed to prepare unit active rentals check (Gagal transaction): " . mysqli_error($conn));
                                    }
                                }
                            }


                            $log_id = generateUUID();
                            $log_action = "Tambah Transaksi";
                            $log_user = $_SESSION['username'];
                            $status_awal = $status;
                            $jumlah_awal = $jumlah;

                            $log_stmt = $conn->prepare("INSERT INTO log_transaksi (id_log, id_transaksi, aksi, status_lama, status_baru, jumlah_lama, jumlah_baru, user_log) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($log_stmt) {
                                $log_stmt->bind_param("sssssdds", $log_id, $id_transaksi, $log_action, $status_awal, $status, $jumlah_awal, $jumlah, $log_user);
                                $log_stmt->execute();
                                $log_stmt->close();
                                error_log("Log added for transaction: " . $id_transaksi . " with action " . $log_action);
                            } else {
                                error_log("Failed to prepare log statement (add): " . mysqli_error($conn));
                            }
                        } else {
                            $error_message = "Gagal menambahkan transaksi: Terjadi kesalahan eksekusi statement.";
                            error_log("Failed to execute insert transaction: " . $stmt->error);
                        }
                    } catch (mysqli_sql_exception $e) {
                        $error_message = "Gagal menambahkan transaksi: " . $e->getMessage();
                        error_log("MySQLi Exception during ADD: " . $e->getMessage());
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement tambah transaksi: " . mysqli_error($conn);
                    error_log("Failed to prepare add transaction statement: " . mysqli_error($conn));
                }
                break;

            case 'edit':
                $id_transaksi = $_POST['id_transaksi'];
                $id_sewa = trim($_POST['id_sewa']);
                $tanggal_pembayaran = trim($_POST['tanggal_pembayaran']);
                $jumlah = trim($_POST['jumlah']);
                $metode_pembayaran = trim($_POST['metode_pembayaran']);
                $status = trim($_POST['status']);
                $tipe_pembayaran = isset($_POST['tipe_pembayaran']) ? trim($_POST['tipe_pembayaran']) : 'bulanan';

                if (empty($id_sewa) || empty($jumlah) || empty($metode_pembayaran) || empty($status) || empty($tanggal_pembayaran)) {
                    $error_message = "Semua field wajib diisi!";
                    break;
                }

                $old_data = null;
                $old_data_stmt = $conn->prepare("SELECT status, jumlah, tipe_pembayaran, id_sewa FROM transaksi WHERE id_transaksi = ?");
                if ($old_data_stmt) {
                    $old_data_stmt->bind_param("s", $id_transaksi);
                    $old_data_stmt->execute();
                    $old_data_result = $old_data_stmt->get_result();
                    $old_data = $old_data_result->fetch_assoc();
                    $old_data_stmt->close();
                    error_log("Edit: Old data fetched: " . print_r($old_data, true));
                } else {
                    $error_message = "Gagal menyiapkan statement pengambilan data lama: " . mysqli_error($conn);
                    error_log("Edit: Failed to prepare old data fetch: " . mysqli_error($conn));
                    break;
                }

                if ($old_data['status'] === 'Lunas' && $status !== 'Lunas') {
                    $error_message = "Transaksi yang sudah Lunas tidak dapat diubah ke status lain!";
                    error_log("Edit: Blocking change from Lunas for transaction: " . $id_transaksi);
                    break;
                }

                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_pembayaran)) {
                    $error_message = "Format tanggal tidak valid. Gunakan date picker yang tersedia.";
                    break;
                }

                $date_parts = explode('-', $tanggal_pembayaran);
                if (!checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
                    $error_message = "Tanggal pembayaran tidak valid.";
                    break;
                }

                if (!is_numeric($jumlah) || $jumlah <= 0) {
                    $error_message = "Jumlah pembayaran harus berupa angka positif!";
                    break;
                }

                $get_sewa_dates_query_edit = "SELECT tanggal_mulai, tanggal_selesai FROM sewa WHERE id_sewa = ?";
                $dates_stmt_edit = $conn->prepare($get_sewa_dates_query_edit);
                if ($dates_stmt_edit) {
                    $dates_stmt_edit->bind_param("s", $id_sewa);
                    $dates_stmt_edit->execute();
                    $result_dates_edit = $dates_stmt_edit->get_result();
                    $sewa_dates_edit = $result_dates_edit->fetch_assoc();
                    $dates_stmt_edit->close();

                    if ($sewa_dates_edit) {
                        $sewa_start_edit = strtotime($sewa_dates_edit['tanggal_mulai']);
                        $sewa_end_edit = strtotime($sewa_dates_edit['tanggal_selesai']);
                        $payment_date_edit_timestamp = strtotime($tanggal_pembayaran);

                        if ($payment_date_edit_timestamp < $sewa_start_edit || $payment_date_edit_timestamp > $sewa_end_edit) {
                            $error_message = "Tanggal pembayaran (" . date('d F Y', $payment_date_edit_timestamp) . ") harus berada dalam rentang kontrak sewa (" . date('d F Y', $sewa_start_edit) . " - " . date('d F Y', $sewa_end_edit) . ").";
                            error_log("Edit: Payment date outside rental contract range: " . $error_message);
                            break;
                        }
                    } else {
                        $error_message = "Data kontrak sewa tidak ditemukan untuk ID yang dipilih.";
                        error_log("Edit: Rental contract data not found for id_sewa: " . $id_sewa);
                        break;
                    }
                } else {
                    $error_message = "Gagal menyiapkan pengecekan rentang tanggal sewa saat edit: " . mysqli_error($conn);
                    error_log("Edit: Failed to prepare rental date range check query: " . mysqli_error($conn));
                    break;
                }

                $stmt = $conn->prepare("UPDATE transaksi SET id_sewa = ?, tanggal_pembayaran = ?, jumlah = ?, metode_pembayaran = ?, status = ?, tipe_pembayaran = ? WHERE id_transaksi = ?");

                if ($stmt) {
                    $stmt->bind_param("ssdssss", $id_sewa, $tanggal_pembayaran, $jumlah, $metode_pembayaran, $status, $tipe_pembayaran, $id_transaksi);

                    try {
                        if ($stmt->execute()) {
                            $success_message = "Data transaksi berhasil diupdate!";

                            $current_date = date('Y-m-d');
                            $sewa_info_after_trans_update = null;
                            $get_sewa_info_stmt = $conn->prepare("SELECT id_unit, status FROM sewa WHERE id_sewa = ?");
                            if ($get_sewa_info_stmt) {
                                $get_sewa_info_stmt->bind_param("s", $id_sewa);
                                $get_sewa_info_stmt->execute();
                                $sewa_info_after_trans_update = $get_sewa_info_stmt->get_result()->fetch_assoc();
                                $get_sewa_info_stmt->close();
                            } else {
                                error_log("Failed to prepare sewa info fetch after trans update: " . mysqli_error($conn));
                            }


                            if ($status === 'Lunas' && $old_data['status'] !== 'Lunas') {
                                if ($sewa_info_after_trans_update && $sewa_info_after_trans_update['status'] !== 'Aktif') {
                                    $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Aktif' WHERE id_sewa = ?");
                                    if ($update_sewa_stmt) {
                                        $update_sewa_stmt->bind_param("s", $id_sewa);
                                        $update_sewa_stmt->execute();
                                        $update_sewa_stmt->close();
                                        error_log("Sewa status updated to Aktif (via Lunas transaction) for sewa ID: " . $id_sewa);
                                    } else {
                                        error_log("Failed to prepare update sewa status (Lunas): " . mysqli_error($conn));
                                    }
                                }

                                if ($sewa_info_after_trans_update && !empty($sewa_info_after_trans_update['id_unit'])) {
                                    $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Terisi' WHERE id_unit = ?");
                                    if ($update_unit_stmt) {
                                        $update_unit_stmt->bind_param("s", $sewa_info_after_trans_update['id_unit']);
                                        $update_unit_stmt->execute();
                                        $update_unit_stmt->close();
                                        error_log("Unit status updated to Terisi (via Lunas transaction) for unit ID: " . $sewa_info_after_trans_update['id_unit']);
                                    } else {
                                        error_log("Failed to prepare update unit status (Terisi): " . mysqli_error($conn));
                                    }
                                }
                            } else if ($status !== 'Lunas' && $old_data['status'] === 'Lunas') {
                                $check_other_lunas_trans_stmt = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE id_sewa = ? AND status = 'Lunas' AND id_transaksi != ?");
                                if ($check_other_lunas_trans_stmt) {
                                    $check_other_lunas_trans_stmt->bind_param("ss", $id_sewa, $id_transaksi);
                                    $check_other_lunas_trans_stmt->execute();
                                    $other_lunas_count = $check_other_lunas_trans_stmt->get_result()->fetch_row()[0];
                                    $check_other_lunas_trans_stmt->close();

                                    if ($other_lunas_count == 0) {
                                        $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Menunggu Pembayaran' WHERE id_sewa = ? AND status != 'Selesai' AND status != 'Dibatalkan'");
                                        if ($update_sewa_stmt) {
                                            $update_sewa_stmt->bind_param("s", $id_sewa);
                                            $update_sewa_stmt->execute();
                                            $update_sewa_stmt->close();
                                            error_log("Sewa status updated to Menunggu Pembayaran (Lunas reverted) for sewa ID: " . $id_sewa);
                                        } else {
                                            error_log("Failed to prepare update sewa status (Menunggu Pembayaran): " . mysqli_error($conn));
                                        }

                                        if ($sewa_info_after_trans_update && !empty($sewa_info_after_trans_update['id_unit'])) {
                                            $check_unit_active_rentals_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                                            if ($check_unit_active_rentals_stmt) {
                                                $check_unit_active_rentals_stmt->bind_param("s", $sewa_info_after_trans_update['id_unit']);
                                                $check_unit_active_rentals_stmt->execute();
                                                $unit_active_count = $check_unit_active_rentals_stmt->get_result()->fetch_row()[0];
                                                $check_unit_active_rentals_stmt->close();

                                                if ($unit_active_count == 0) {
                                                    $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                                    if ($update_unit_stmt) {
                                                        $update_unit_stmt->bind_param("s", $sewa_info_after_trans_update['id_unit']);
                                                        $update_unit_stmt->execute();
                                                        $update_unit_stmt->close();
                                                        error_log("Unit status updated to Tersedia (Lunas reverted) for unit ID: " . $sewa_info_after_trans_update['id_unit']);
                                                    } else {
                                                        error_log("Failed to prepare update unit status (Tersedia): " . mysqli_error($conn));
                                                    }
                                                }
                                            } else {
                                                error_log("Failed to prepare unit active rentals check: " . mysqli_error($conn));
                                            }
                                        }
                                    }
                                } else {
                                    error_log("Other Lunas transactions still exist for sewa ID " . $id_sewa . ". No sewa/unit status change.");
                                }
                            }
                            if ($status === 'Gagal' && $old_data['status'] !== 'Gagal') {
                                $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Dibatalkan' WHERE id_sewa = ?");
                                if ($update_sewa_stmt) {
                                    $update_sewa_stmt->bind_param("s", $id_sewa);
                                    $update_sewa_stmt->execute();
                                    $update_sewa_stmt->close();
                                    error_log("Sewa status updated to Dibatalkan (via Gagal transaction) for sewa ID: " . $id_sewa);
                                } else {
                                    error_log("Failed to prepare update sewa status (Dibatalkan): " . mysqli_error($conn));
                                }

                                if ($sewa_info_after_trans_update && !empty($sewa_info_after_trans_update['id_unit'])) {
                                    $check_other_active_rentals_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                                    if ($check_other_active_rentals_stmt) {
                                        $check_other_active_rentals_stmt->bind_param("s", $sewa_info_after_trans_update['id_unit']);
                                        $check_other_active_rentals_stmt->execute();
                                        $other_active_count = $check_other_active_rentals_stmt->get_result()->fetch_row()[0];
                                        $check_other_active_rentals_stmt->close();

                                        if ($other_active_count == 0) {
                                            $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                            if ($update_unit_stmt) {
                                                $update_unit_stmt->bind_param("s", $sewa_info_after_trans_update['id_unit']);
                                                $update_unit_stmt->execute();
                                                $update_unit_stmt->close();
                                                error_log("Unit status updated to Tersedia (via Gagal transaction) for unit ID: " . $sewa_info_after_trans_update['id_unit']);
                                            } else {
                                                error_log("Failed to prepare update unit status (Tersedia, Gagal transaction): " . mysqli_error($conn));
                                            }
                                        }
                                    } else {
                                        error_log("Failed to prepare unit active rentals check (Gagal transaction): " . mysqli_error($conn));
                                    }
                                }
                            }

                            $log_id = generateUUID();
                            $log_action = "Update Transaksi";
                            $log_user = $_SESSION['username'];
                            $log_stmt = $conn->prepare("INSERT INTO log_transaksi (id_log, id_transaksi, aksi, status_lama, status_baru, jumlah_lama, jumlah_baru, user_log) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($log_stmt) {
                                $log_stmt->bind_param("sssssdds", $log_id, $id_transaksi, $log_action, $old_data['status'], $status, $old_data['jumlah'], $jumlah, $log_user);
                                $log_stmt->execute();
                                $log_stmt->close();
                                error_log("Log added for edited transaction: " . $id_transaksi . " action " . $log_action);
                            } else {
                                error_log("Failed to prepare log statement (edit): " . mysqli_error($conn));
                            }
                        } else {
                            $error_message = "Gagal mengupdate transaksi: Terjadi kesalahan eksekusi statement.";
                            error_log("Failed to execute update transaction: " . $stmt->error);
                        }
                    } catch (mysqli_sql_exception $e) {
                        $error_message = "Gagal mengupdate transaksi: " . $e->getMessage();
                        error_log("MySQLi Exception during EDIT: " . $e->getMessage());
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement edit transaksi: " . mysqli_error($conn);
                    error_log("Failed to prepare update transaction statement: " . mysqli_error($conn));
                }
                break;

            case 'delete':
                $id_transaksi = $_POST['id_transaksi'];
                $old_data = null;

                $old_data_stmt = $conn->prepare("SELECT status, jumlah, id_sewa FROM transaksi WHERE id_transaksi = ?");
                if ($old_data_stmt) {
                    $old_data_stmt->bind_param("s", $id_transaksi);
                    $old_data_stmt->execute();
                    $old_data_result = $old_data_stmt->get_result();
                    $old_data = $old_data_result->fetch_assoc();
                    $old_data_stmt->close();
                    error_log("Delete: Old data fetched for transaction: " . $id_transaksi);
                } else {
                    $error_message = "Gagal menyiapkan statement pengambilan data lama sebelum hapus: " . mysqli_error($conn);
                    error_log("Delete: Failed to prepare old data fetch: " . mysqli_error($conn));
                    break;
                }

                if ($old_data['status'] === 'Lunas') {
                    $error_message = "Transaksi yang sudah Lunas tidak dapat dihapus!";
                    error_log("Delete: Blocking delete for Lunas transaction: " . $id_transaksi);
                    break;
                }

                $stmt = $conn->prepare("DELETE FROM transaksi WHERE id_transaksi = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_transaksi);

                    try {
                        if ($stmt->execute()) {
                            $success_message = "Transaksi berhasil dihapus!";
                            error_log("Transaction deleted successfully: " . $id_transaksi);

                            if ($old_data['status'] === 'Lunas') { // Although blocked above, this is a safety check
                                $check_other_lunas_trans_stmt = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE id_sewa = ? AND status = 'Lunas'");
                                if ($check_other_lunas_trans_stmt) {
                                    $check_other_lunas_trans_stmt->bind_param("s", $old_data['id_sewa']);
                                    $check_other_lunas_trans_stmt->execute();
                                    $remaining_lunas_count = $check_other_lunas_trans_stmt->get_result()->fetch_row()[0];
                                    $check_other_lunas_trans_stmt->close();

                                    if ($remaining_lunas_count == 0) {
                                        $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Menunggu Pembayaran' WHERE id_sewa = ? AND status != 'Selesai' AND status != 'Dibatalkan'");
                                        if ($update_sewa_stmt) {
                                            $update_sewa_stmt->bind_param("s", $old_data['id_sewa']);
                                            $update_sewa_stmt->execute();
                                            $update_sewa_stmt->close();
                                            error_log("Sewa status reverted to Menunggu Pembayaran (Lunas transaction deleted) for sewa ID: " . $old_data['id_sewa']);
                                        } else {
                                            error_log("Failed to prepare update sewa status after delete: " . mysqli_error($conn));
                                        }

                                        $get_unit_id_stmt = $conn->prepare("SELECT id_unit FROM sewa WHERE id_sewa = ?");
                                        $unit_id_for_delete_check = null;
                                        if ($get_unit_id_stmt) {
                                            $get_unit_id_stmt->bind_param("s", $old_data['id_sewa']);
                                            $get_unit_id_stmt->execute();
                                            $unit_id_result = $get_unit_id_stmt->get_result();
                                            if ($row = $unit_id_result->fetch_assoc()) {
                                                $unit_id_for_delete_check = $row['id_unit'];
                                            }
                                            $get_unit_id_stmt->close();
                                        }

                                        if ($unit_id_for_delete_check) {
                                            $check_unit_active_rentals_after_delete_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                                            if ($check_unit_active_rentals_after_delete_stmt) {
                                                $check_unit_active_rentals_after_delete_stmt->bind_param("s", $unit_id_for_delete_check);
                                                $check_unit_active_rentals_after_delete_stmt->execute();
                                                $unit_active_count_after_delete = $check_unit_active_rentals_after_delete_stmt->get_result()->fetch_row()[0];
                                                $check_unit_active_rentals_after_delete_stmt->close();

                                                if ($unit_active_count_after_delete == 0) {
                                                    $update_unit_stmt = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                                    if ($update_unit_stmt) {
                                                        $update_unit_stmt->bind_param("s", $unit_id_for_delete_check);
                                                        $update_unit_stmt->execute();
                                                        $update_unit_stmt->close();
                                                        error_log("Unit status reverted to Tersedia (Lunas transaction deleted) for unit ID: " . $unit_id_for_delete_check);
                                                    } else {
                                                        error_log("Failed to prepare update unit status after delete: " . mysqli_error($conn));
                                                    }
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    error_log("Other Lunas transactions still exist for sewa ID " . $old_data['id_sewa'] . ". No sewa/unit status change after transaction delete.");
                                }
                            } else if ($old_data['status'] === 'Gagal') {
                                $check_other_gagal_trans_stmt = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE id_sewa = ? AND status = 'Gagal' AND id_transaksi != ?");
                                if ($check_other_gagal_trans_stmt) {
                                    $check_other_gagal_trans_stmt->bind_param("ss", $old_data['id_sewa'], $id_transaksi);
                                    $check_other_gagal_trans_stmt->execute();
                                    $remaining_gagal_count = $check_other_gagal_trans_stmt->get_result()->fetch_row()[0];
                                    $check_other_gagal_trans_stmt->close();

                                    if ($remaining_gagal_count == 0) {
                                        $update_sewa_stmt = $conn->prepare("UPDATE sewa SET status = 'Menunggu Pembayaran' WHERE id_sewa = ? AND status = 'Dibatalkan'");
                                        if ($update_sewa_stmt) {
                                            $update_sewa_stmt->bind_param("s", $old_data['id_sewa']);
                                            $update_sewa_stmt->execute();
                                            $update_sewa_stmt->close();
                                            error_log("Sewa status reverted from Dibatalkan (Gagal transaction deleted) for sewa ID: " . $old_data['id_sewa']);
                                        } else {
                                            error_log("Failed to prepare update sewa status after deleting Gagal trans: " . mysqli_error($conn));
                                        }
                                    }
                                }
                            }

                        } else {
                            $error_message = "Gagal menghapus transaksi: Terjadi kesalahan eksekusi statement.";
                            error_log("Failed to delete transaction: " . $stmt->error);
                        }
                    } catch (mysqli_sql_exception $e) {
                        $error_message = "Gagal menghapus transaksi: " . $e->getMessage();
                        error_log("MySQLi Exception during DELETE: " . $e->getMessage());
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement hapus transaksi: " . mysqli_error($conn);
                    error_log("Failed to prepare delete transaction statement: " . mysqli_error($conn));
                }
                break;
        }
    }

    error_log("--- TRANSKSI.PHP POST REQUEST END ---");
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_metode = isset($_GET['filter_metode']) ? trim($_GET['filter_metode']) : '';

$query = "
    SELECT
        t.*,
        s.tanggal_mulai,
        s.tanggal_selesai,
        u.nomor_unit,
        b.nama_bangunan,
        p.nama as nama_penyewa
    FROM transaksi t
    LEFT JOIN sewa s ON t.id_sewa = s.id_sewa
    LEFT JOIN unit u ON s.id_unit = u.id_unit
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    LEFT JOIN penyewa p ON s.id_penyewa = p.id_penyewa
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (u.nomor_unit LIKE ? OR b.nama_bangunan LIKE ? OR p.nama LIKE ? OR t.metode_pembayaran LIKE ? OR t.id_transaksi LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sssss";
}

if (!empty($filter_status)) {
    $query .= " AND t.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if (!empty($filter_metode)) {
    $query .= " AND t.metode_pembayaran = ?";
    $params[] = $filter_metode;
    $types .= "s";
}

$query .= " ORDER BY t.tanggal_pembayaran DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $transaksi_list = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $transaksi_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data transaksi: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data transaksi: " . mysqli_error($conn);
}

$sewa_stmt = $conn->prepare("
    SELECT
        s.id_sewa,
        u.nomor_unit,
        b.nama_bangunan,
        p.nama as nama_penyewa,
        u.harga_sewa
    FROM sewa s
    LEFT JOIN unit u ON s.id_unit = u.id_unit
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    LEFT JOIN penyewa p ON s.id_penyewa = p.id_penyewa
    WHERE s.status IN ('Aktif', 'Menunggu Pembayaran')
    ORDER BY b.nama_bangunan, u.nomor_unit
");
$sewa_options = [];
if ($sewa_stmt) {
    if ($sewa_stmt->execute()) {
        $sewa_result = $sewa_stmt->get_result();
        $sewa_options = $sewa_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data sewa untuk dropdown: " . $sewa_stmt->error;
    }
    $sewa_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query sewa untuk dropdown: " . mysqli_error($conn);
}

$stats_query = "
    SELECT
        COUNT(DISTINCT id_transaksi) as total_transaksi,
        SUM(CASE WHEN status = 'Lunas' THEN jumlah ELSE 0 END) as total_lunas,
        SUM(CASE WHEN status = 'Pending' THEN jumlah ELSE 0 END) as total_pending
    FROM transaksi
";
$stats_stmt = $conn->prepare($stats_query);
$stats = ['total_transaksi' => 0, 'total_lunas' => 0, 'total_pending' => 0];

if ($stats_stmt) {
    if ($stats_stmt->execute()) {
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil statistik: " . $stats_stmt->error;
    }
    $stats_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query statistik: " . mysqli_error($conn);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Transaksi - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-handshake"></i> Manajemen Transaksi - SiKontra</h1>
        <div class="user-info">
            <span>Selamat datang, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></span>
            <span class="role-badge role-<?php echo $_SESSION['role']; ?>">
                <?php echo $_SESSION['role'] === 'super_admin' ? 'Super Admin' : 'Admin'; ?>
            </span>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title-section">
                <div class="breadcrumb">
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Transaksi
                </div>
                <h1>Manajemen Transaksi</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Transaksi Baru
            </button>
            <a href="log_transaksi.php?origin=transaksi" class="add-button"
                style="background: linear-gradient(135deg, #71C0BB, #5a9b96); margin-left: 10px;">
                <i class="fas fa-history"></i> Lihat Log Transaksi
            </a>
        </div>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo $success_message; ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo $error_message; ?></span>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_transaksi']); ?></div>
                <div class="label">Total Transaksi</div>
            </div>
            <div class="stat-card">
                <div class="number">Rp <?php echo number_format($stats['total_lunas'], 0, ',', '.'); ?></div>
                <div class="label">Total Lunas</div>
            </div>
            <div class="stat-card">
                <div class="number">Rp <?php echo number_format($stats['total_pending'], 0, ',', '.'); ?></div>
                <div class="label">Total Pending</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" id="filterForm" class="search-form-container">
                    <div class="search-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Cari nomor unit, bangunan, atau penyewa..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <select name="filter_status" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Semua Status</option>
                            <option value="Lunas" <?php echo ($filter_status == 'Lunas') ? 'selected' : ''; ?>>Lunas</option>
                            <option value="Pending" <?php echo ($filter_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Gagal" <?php echo ($filter_status == 'Gagal') ? 'selected' : ''; ?>>Gagal</option>
                        </select>
                        <select name="filter_metode" class="filter-select" onchange="document.getElementById('filterForm').submit()">
                            <option value="">Semua Metode</option>
                            <option value="Tunai" <?php echo ($filter_metode == 'Tunai') ? 'selected' : ''; ?>>Tunai</option>
                            <option value="Transfer Bank" <?php echo ($filter_metode == 'Transfer Bank') ? 'selected' : ''; ?>>Transfer Bank</option>
                            <option value="E-Wallet" <?php echo ($filter_metode == 'E-Wallet') ? 'selected' : ''; ?>>E-Wallet</option>
                            <option value="Debit Card" <?php echo ($filter_metode == 'Debit Card') ? 'selected' : ''; ?>>Debit Card</option>
                        </select>
                        <button type="button" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search) || !empty($filter_status) || !empty($filter_metode)): ?>
                    Menampilkan <?php echo count($transaksi_list); ?> hasil
                <?php else: ?>
                    Total <?php echo count($transaksi_list); ?> transaksi terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Transaksi Terdaftar</span>
            </div>
            <?php if (empty($transaksi_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <?php if (!empty($search) || !empty($filter_status) || !empty($filter_metode)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada transaksi yang cocok dengan kriteria pencarian Anda.</p>
                    <?php else: ?>
                        <h3>Belum ada data transaksi</h3>
                        <p>Sistem belum memiliki data transaksi yang terdaftar. Klik tombol "Tambah Transaksi Baru" untuk
                            menambahkan data transaksi pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar-alt"></i> Tanggal Pembayaran</th>
                                <th><i class="fas fa-money-bill-wave"></i> Jumlah</th>
                                <th><i class="fas fa-credit-card"></i> Metode</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-home"></i> Bangunan</th>
                                <th><i class="fas fa-door-open"></i> Unit</th>
                                <th><i class="fas fa-user-tag"></i> Penyewa</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transaksi_list as $transaksi): ?>
                                <tr>
                                    <td class="data-cell">
                                        <?php
                                        $db_date = $transaksi['tanggal_pembayaran'];
                                        if ($db_date === '0000-00-00' || empty($db_date) || !strtotime($db_date)) {
                                            echo '<em style="color: #999;">Tanggal Tidak Valid</em>';
                                        } else {
                                            echo htmlspecialchars(date('d F Y', strtotime($db_date)));
                                        }
                                        ?>
                                    </td>
                                    <td class="data-cell primary">
                                        <strong>Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?></strong>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($transaksi['metode_pembayaran']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <span class="status-badge status-<?php echo htmlspecialchars($transaksi['status']); ?>">
                                            <?php echo htmlspecialchars($transaksi['status']); ?>
                                        </span>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($transaksi['nama_bangunan'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($transaksi['nomor_unit'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($transaksi['nama_penyewa'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit" onclick="openEditModal(
                                                    '<?php echo $transaksi['id_transaksi']; ?>',
                                                    '<?php echo htmlspecialchars($transaksi['id_sewa']); ?>',
                                                    '<?php echo htmlspecialchars($transaksi['tanggal_pembayaran']); ?>',
                                                    '<?php echo htmlspecialchars($transaksi['jumlah']); ?>',
                                                    '<?php echo htmlspecialchars($transaksi['metode_pembayaran']); ?>',
                                                    '<?php echo htmlspecialchars($transaksi['status']); ?>',
                                                    '<?php echo htmlspecialchars($transaksi['tipe_pembayaran'] ?? 'bulanan'); ?>'
                                                )">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo $transaksi['id_transaksi']; ?>', 'Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?> (<?php echo htmlspecialchars($transaksi['nama_penyewa'] ?? 'N/A'); ?>)')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                            <a href="log_transaksi.php?id_transaksi=<?php echo htmlspecialchars($transaksi['id_transaksi']); ?>"
                                                class="btn-edit"
                                                style="background: #332D56; color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 0.8em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                                                <i class="fas fa-clipboard-list"></i> Log
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="transaksiModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Tambah Transaksi Baru</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="transaksiForm" method="POST">
                    <input type="hidden" id="formAction" name="action" value="add">
                    <input type="hidden" id="formIdTransaksi" name="id_transaksi" value="">

                    <div class="form-group">
                        <label for="id_sewa">Data Sewa (Unit & Penyewa) *</label>
                        <select id="id_sewa" name="id_sewa" required onchange="updateJumlah()">
                            <option value="">Pilih Sewa</option>
                            <?php foreach ($sewa_options as $sewa): ?>
                                <option value="<?php echo $sewa['id_sewa']; ?>"
                                    data-harga-sewa="<?php echo htmlspecialchars($sewa['harga_sewa']); ?>">
                                    <?php echo htmlspecialchars($sewa['nama_bangunan'] . ' - Unit ' . $sewa['nomor_unit'] . ' (Penyewa: ' . $sewa['nama_penyewa'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tipe_pembayaran">Tipe Pembayaran *</label>
                        <select id="tipe_pembayaran" name="tipe_pembayaran" required onchange="updateJumlah()">
                            <option value="bulanan">Bulanan</option>
                            <option value="tahunan">Tahunan</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="tanggal_pembayaran">Tanggal Pembayaran *</label>
                        <input type="date" id="tanggal_pembayaran" name="tanggal_pembayaran" required>
                    </div>

                    <div class="form-group">
                        <label for="jumlah">Jumlah Pembayaran *</label>
                        <input type="number" id="jumlah" name="jumlah" step="0.01" min="0" required
                            placeholder="Contoh: 500000.00">
                    </div>

                    <div class="form-group">
                        <label for="metode_pembayaran">Metode Pembayaran *</label>
                        <select id="metode_pembayaran" name="metode_pembayaran" required>
                            <option value="">Pilih Metode</option>
                            <option value="Tunai">Tunai</option>
                            <option value="Transfer Bank">Transfer Bank</option>
                            <option value="E-Wallet">E-Wallet</option>
                            <option value="Debit Card">Debit Card</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status">Status Transaksi *</label>
                        <select id="status" name="status" required>
                            <option value="">Pilih Status</option>
                            <option value="Lunas">Lunas</option>
                            <option value="Pending">Pending</option>
                            <option value="Gagal">Gagal</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Simpan Data Transaksi
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #C08B8B, #a67373);">
                <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Hapus</h3>
                <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-trash-alt" style="font-size: 3em; color: #C08B8B; margin-bottom: 20px;"></i>
                    <p style="font-size: 1.1em; margin-bottom: 10px; color: #333;">Apakah Anda yakin ingin menghapus
                        transaksi:</p>
                    <p id="deleteTransaksiInfo"
                        style="font-weight: 600; color: #C08B8B; font-size: 1.2em; margin-bottom: 20px;"></p>
                    <div
                        style="background-color: #f8d7da; border: 1px solid #dc3545; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #721c24; font-size: 0.9em; margin: 0;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 5px;"></i>
                            <strong>Peringatan:</strong> Tindakan ini akan menghapus data transaksi secara permanen dan
                            tidak dapat dibatalkan.
                        </p>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="closeDeleteModal()"
                        style="flex: 1; padding: 12px; border: 2px solid #6c757d; background: white; color: #6c757d; border-radius: 8px; font-weight: 600; cursor: pointer;">
                        <i class="fas fa-times"></i> Batal
                    </button>
                    <button type="button" onclick="executeDelete()"
                        style="flex: 1; padding: 12px; border: none; background: linear-gradient(135deg, #C08B8B, #a67373); color: white; border-radius: 8px; font-weight: 600; cursor: pointer;">
                        <i class="fas fa-trash"></i> Ya, Hapus
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" id="delete_id" name="id_transaksi">
    </form>

    <script>
        let deleteTransaksiId = '';
        let deleteTransaksiInfo = '';

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Transaksi Baru';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formIdTransaksi').value = '';
            document.getElementById('transaksiForm').reset();
            document.getElementById('tanggal_pembayaran').valueAsDate = new Date();
            document.getElementById('transaksiModal').style.display = 'block';

            const urlParams = new URLSearchParams(window.location.search);
            const idSewaFromUrl = urlParams.get('id_sewa');
            if (idSewaFromUrl) {
                document.getElementById('id_sewa').value = idSewaFromUrl;
                updateJumlah();
            }
        }

        function openEditModal(id_transaksi, id_sewa, tanggal_pembayaran, jumlah, metode_pembayaran, status, tipe_pembayaran) {
            document.getElementById('modalTitle').textContent = 'Edit Data Transaksi';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formIdTransaksi').value = id_transaksi;
            document.getElementById('id_sewa').value = id_sewa;
            document.getElementById('tanggal_pembayaran').value = tanggal_pembayaran;
            document.getElementById('jumlah').value = jumlah;
            document.getElementById('metode_pembayaran').value = metode_pembayaran;
            document.getElementById('status').value = status;
            document.getElementById('tipe_pembayaran').value = tipe_pembayaran;
            document.getElementById('transaksiModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('transaksiModal').style.display = 'none';
        }

        function confirmDelete(id, info) {
            deleteTransaksiId = id;
            deleteTransaksiInfo = info;
            document.getElementById('deleteTransaksiInfo').textContent = info;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function executeDelete() {
            document.getElementById('delete_id').value = deleteTransaksiId;
            document.getElementById('deleteForm').submit();
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function updateJumlah() {
            const sewaSelect = document.getElementById('id_sewa');
            const jumlahInput = document.getElementById('jumlah');
            const tipePembayaranSelect = document.getElementById('tipe_pembayaran');
            const selectedOption = sewaSelect.options[sewaSelect.selectedIndex];
            const hargaSewa = selectedOption.getAttribute('data-harga-sewa');

            if (hargaSewa) {
                let calculatedAmount = parseFloat(hargaSewa);
                if (tipePembayaranSelect.value === 'tahunan') {
                    calculatedAmount *= 12;
                }
                jumlahInput.value = calculatedAmount.toFixed(2);
            } else {
                jumlahInput.value = '';
            }
        }

        function resetFilters() {
        window.location.href = 'transaksi.php';
        }

        window.onclick = function (event) {
            const modals = ['transaksiModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const tanggalPembayaranInput = document.getElementById('tanggal_pembayaran');
            if (document.getElementById('formAction').value === 'add' && !tanggalPembayaranInput.value) {
                tanggalPembayaranInput.valueAsDate = new Date();
            }

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function () {
                        alert.remove();
                    }, 300);
                }, 5000);
            });

            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'add' && urlParams.get('id_sewa')) {
                openAddModal();
            }
        });

        const searchInput = document.querySelector('#filterForm input[name="search"]'); // Target specific search input
        if (searchInput) {
            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault(); // Prevent default form submission behavior (which might be for a different form)
                    document.getElementById('filterForm').submit(); // Submit the main filter form
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openAddModal();
            }

            if (e.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const forms = document.querySelectorAll('form');
            forms.forEach(function (form) {
                form.addEventListener('submit', function () {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.style.opacity = '0.7';
                        submitBtn.style.cursor = 'not-allowed';
                        submitBtn.disabled = true;

                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';

                        setTimeout(function () {
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }, 3000);
                    }
                });
            });
        });
    </script>
</body>

</html>