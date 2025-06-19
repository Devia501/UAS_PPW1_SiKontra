<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$success_message = '';
$error_message = '';

$current_date = date('Y-m-d');

$auto_set_selesai_stmt = $conn->prepare("UPDATE sewa SET status = 'Selesai' WHERE tanggal_selesai < ? AND status NOT IN ('Selesai', 'Dibatalkan')");
if ($auto_set_selesai_stmt) {
    $auto_set_selesai_stmt->bind_param("s", $current_date);
    if ($auto_set_selesai_stmt->execute()) {
        if ($conn->affected_rows > 0) {
            error_log("Automatically updated " . $conn->affected_rows . " sewa records to 'Selesai'.");
        }
    } else {
        error_log("Failed to auto-update sewa status to 'Selesai': " . $auto_set_selesai_stmt->error);
        $error_message = (empty($error_message) ? '' : '<br>') . "Gagal melakukan pembaruan otomatis status 'Selesai'.";
    }
    $auto_set_selesai_stmt->close();
} else {
    error_log("Failed to prepare auto-set-selesai query: " . mysqli_error($conn));
    $error_message = (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query otomatis status 'Selesai'.";
}

$correct_inconsistent_status_stmt = $conn->prepare("
    UPDATE sewa s
    LEFT JOIN (
        SELECT id_sewa, COUNT(id_transaksi) AS lunas_count
        FROM transaksi
        WHERE status = 'Lunas'
        GROUP BY id_sewa
    ) t ON s.id_sewa = t.id_sewa
    SET s.status = CASE
        WHEN t.lunas_count > 0 THEN 'Aktif'
        ELSE 'Menunggu Pembayaran'
    END
    WHERE s.status = 'Selesai' AND s.tanggal_selesai >= ?
");

if ($correct_inconsistent_status_stmt) {
    $correct_inconsistent_status_stmt->bind_param("s", $current_date);
    if ($correct_inconsistent_status_stmt->execute()) {
        if ($conn->affected_rows > 0) {
            error_log("Corrected " . $conn->affected_rows . " inconsistent sewa records.");
        }
    } else {
        error_log("Failed to correct inconsistent sewa status: " . $correct_inconsistent_status_stmt->error);
        $error_message = (empty($error_message) ? '' : '<br>') . "Gagal melakukan koreksi otomatis status sewa.";
    }
    $correct_inconsistent_status_stmt->close();
} else {
    error_log("Failed to prepare inconsistent status correction query: " . mysqli_error($conn));
    $error_message = (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query koreksi status sewa.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $id_unit = trim($_POST['id_unit']);
                $id_penyewa = trim($_POST['id_penyewa']);
                $tanggal_mulai = trim($_POST['tanggal_mulai']);
                $tanggal_selesai = trim($_POST['tanggal_selesai']);
                $status = trim($_POST['status']);

                if (empty($id_unit) || empty($id_penyewa) || empty($tanggal_mulai) || empty($tanggal_selesai) || empty($status)) {
                    $error_message = "Semua field wajib diisi!";
                } else {
                    if (strtotime($tanggal_mulai) > strtotime($tanggal_selesai)) {
                        $error_message = "Tanggal mulai tidak boleh lebih dari tanggal selesai!";
                        break;
                    }

                    $initial_status = $status;
                    if ($status === 'Aktif') {
                        $status = 'Menunggu Pembayaran';
                        $error_message = "Sewa tidak dapat langsung diaktifkan saat penambahan baru. Harap tambahkan transaksi Lunas setelahnya. Status diatur sebagai 'Menunggu Pembayaran'.";
                    }
                    if ($status === 'Selesai') {
                        $error_message = "Status 'Selesai' tidak dapat dipilih saat menambah sewa baru. Status diatur sebagai 'Menunggu Pembayaran'.";
                        $status = 'Menunggu Pembayaran';
                    }


                    $id_sewa = generateUUID();
                    $stmt = $conn->prepare("INSERT INTO sewa (id_sewa, id_unit, id_penyewa, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $id_sewa, $id_unit, $id_penyewa, $tanggal_mulai, $tanggal_selesai, $status);

                        if ($stmt->execute()) {
                            if (empty($error_message)) {
                                $success_message = "Data sewa berhasil ditambahkan!";
                            }
                            $update_unit_status_value = 'Tersedia'; // Default in case of issues
                            if ($status === 'Aktif') {
                                $update_unit_status_value = 'Terisi';
                            } elseif ($status === 'Dalam Perbaikan') { // This status is for Unit, not Sewa directly from input
                                $update_unit_status_value = 'Dalam Perbaikan'; // Should ideally not happen from Sewa add form
                            }
                            // Re-check actual status based on new sewa
                            $check_unit_status_from_sewa_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                            if ($check_unit_status_from_sewa_stmt) {
                                $check_unit_status_from_sewa_stmt->bind_param("s", $id_unit);
                                $check_unit_status_from_sewa_stmt->execute();
                                $active_sewa_on_unit_count = $check_unit_status_from_sewa_stmt->get_result()->fetch_row()[0];
                                $check_unit_status_from_sewa_stmt->close();

                                if ($active_sewa_on_unit_count > 0) {
                                    $update_unit_status_value = 'Terisi';
                                } else {
                                    // If no active sewa, ensure unit status is 'Tersedia' unless it was 'Dalam Perbaikan'
                                    $get_current_unit_status_stmt = $conn->prepare("SELECT status FROM unit WHERE id_unit = ?");
                                    if ($get_current_unit_status_stmt) {
                                        $get_current_unit_status_stmt->bind_param("s", $id_unit);
                                        $get_current_unit_status_stmt->execute();
                                        $current_unit_status = $get_current_unit_status_stmt->get_result()->fetch_assoc()['status'];
                                        $get_current_unit_status_stmt->close();
                                        if ($current_unit_status !== 'Dalam Perbaikan') {
                                            $update_unit_status_value = 'Tersedia';
                                        } else {
                                            $update_unit_status_value = 'Dalam Perbaikan'; // Preserve 'Dalam Perbaikan'
                                        }
                                    }
                                }
                            }


                            $update_unit_status = $conn->prepare("UPDATE unit SET status = ? WHERE id_unit = ?");
                            if ($update_unit_status) {
                                $update_unit_status->bind_param("ss", $update_unit_status_value, $id_unit);
                                $update_unit_status->execute();
                                $update_unit_status->close();
                            } else {
                                error_log("Failed to prepare unit status update (for sewa add): " . mysqli_error($conn));
                            }
                        } else {
                            $error_message = "Gagal menambahkan data sewa: " . $stmt->error;
                            error_log("Failed to execute add sewa: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement tambah data sewa: " . mysqli_error($conn);
                        error_log("Failed to prepare add sewa statement: " . mysqli_error($conn));
                    }
                }
                break;

            case 'edit':
                $id_sewa = $_POST['id_sewa'];
                $id_unit = trim($_POST['id_unit']);
                $id_penyewa = trim($_POST['id_penyewa']);
                $tanggal_mulai = trim($_POST['tanggal_mulai']);
                $tanggal_selesai = trim($_POST['tanggal_selesai']);
                $status = trim($_POST['status']);

                if (empty($id_unit) || empty($id_penyewa) || empty($tanggal_mulai) || empty($tanggal_selesai) || empty($status)) {
                    $error_message = "Semua field wajib diisi!";
                } else {
                    if (strtotime($tanggal_mulai) > strtotime($tanggal_selesai)) {
                        $error_message = "Tanggal mulai tidak boleh lebih dari tanggal selesai!";
                        break;
                    }

                    $old_info_stmt = $conn->prepare("SELECT status, id_unit, tanggal_mulai FROM sewa WHERE id_sewa = ?");
                    if ($old_info_stmt) {
                        $old_info_stmt->bind_param("s", $id_sewa);
                        $old_info_stmt->execute();
                        $old_info_result = $old_info_stmt->get_result();
                        $old_info = $old_info_result->fetch_assoc();
                        $old_info_stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement pengambilan info sewa lama: " . mysqli_error($conn);
                        error_log("Failed to prepare old sewa info fetch: " . mysqli_error($conn));
                        break;
                    }

                    $old_status = $old_info['status'];
                    $old_unit_id = $old_info['id_unit'];
                    $old_tanggal_mulai = $old_info['tanggal_mulai'];

                    // Status 'Selesai' can only be set if tanggal_selesai has passed
                    if ($status === 'Selesai') {
                        $current_date_for_check = date('Y-m-d');
                        if (strtotime($tanggal_selesai) >= strtotime($current_date_for_check)) {
                            $error_message = "Status 'Selesai' hanya dapat diatur jika Tanggal Selesai sudah terlewati.";
                            break; // Do not proceed with update if validation fails
                        }
                    }

                    // Validation for changing to 'Aktif': must have a 'Lunas' payment covering start month
                    if ($status === 'Aktif' && $old_status !== 'Aktif') {
                        $has_lunas_payment = false;
                        $sewa_start_date_obj_new = new DateTime($tanggal_mulai);

                        // Check for monthly payments for the start month of the new rental period
                        $start_month_new = $sewa_start_date_obj_new->format('Y-m');
                        $check_monthly_payment_edit = $conn->prepare("SELECT COUNT(*) FROM transaksi WHERE id_sewa = ? AND DATE_FORMAT(tanggal_pembayaran, '%Y-%m') = ? AND status = 'Lunas' AND tipe_pembayaran = 'bulanan'");
                        if ($check_monthly_payment_edit) {
                            $check_monthly_payment_edit->bind_param("ss", $id_sewa, $start_month_new);
                            $check_monthly_payment_edit->execute();
                            $result_monthly_edit = $check_monthly_payment_edit->get_result();
                            $row_monthly_edit = $result_monthly_edit->fetch_row();
                            if ($row_monthly_edit[0] > 0) {
                                $has_lunas_payment = true;
                            }
                            $check_monthly_payment_edit->close();
                        } else {
                            $error_message = "Gagal menyiapkan pengecekan pembayaran bulanan (edit): " . mysqli_error($conn);
                            error_log("Failed to prepare monthly payment check (edit): " . mysqli_error($conn));
                            break;
                        }

                        if (!$has_lunas_payment) {
                            // Check for yearly payments that cover the start date of the new rental period
                            $check_yearly_payment_edit = $conn->prepare("SELECT tanggal_pembayaran FROM transaksi WHERE id_sewa = ? AND status = 'Lunas' AND tipe_pembayaran = 'tahunan'");
                            if ($check_yearly_payment_edit) {
                                $check_yearly_payment_edit->bind_param("s", $id_sewa);
                                $check_yearly_payment_edit->execute();
                                $result_yearly_edit = $check_yearly_payment_edit->get_result(); // Corrected variable name
                                while ($yearly_trans_edit = $result_yearly_edit->fetch_assoc()) {
                                    $yearly_payment_date_obj_edit = new DateTime($yearly_trans_edit['tanggal_pembayaran']);
                                    $yearly_coverage_end_edit = (clone $yearly_payment_date_obj_edit)->modify('+1 year -1 day');
                                    if ($sewa_start_date_obj_new >= $yearly_payment_date_obj_edit && $sewa_start_date_obj_new <= $yearly_coverage_end_edit) {
                                        $has_lunas_payment = true;
                                        break;
                                    }
                                }
                                $check_yearly_payment_edit->close();
                            } else {
                                $error_message = "Gagal menyiapkan pengecekan pembayaran tahunan (edit): " . mysqli_error($conn);
                                error_log("Failed to prepare yearly payment check (edit): " . mysqli_error($conn));
                                break;
                            }
                        }

                        if (!$has_lunas_payment) {
                            // Revert status or set to 'Menunggu Pembayaran' if no Lunas payment is found
                            $status_before_attempt = $status; // Store attempted status
                            $status = $old_status; // Revert to old status first
                            if ($old_status === 'Selesai' || $old_status === 'Dibatalkan' || $old_status === 'Menunggu Pembayaran') {
                                $status = 'Menunggu Pembayaran'; // Force to 'Menunggu Pembayaran' if old status was not active
                            }
                            $error_message = "Sewa tidak dapat diaktifkan karena belum ada pembayaran lunas yang tercatat untuk periode awal kontrak (" . date('d F Y', strtotime($tanggal_mulai)) . "). Status dipertahankan atau diatur sebagai 'Menunggu Pembayaran'.";
                        }
                    }

                    $stmt = $conn->prepare("UPDATE sewa SET id_unit = ?, id_penyewa = ?, tanggal_mulai = ?, tanggal_selesai = ?, status = ? WHERE id_sewa = ?");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $id_unit, $id_penyewa, $tanggal_mulai, $tanggal_selesai, $status, $id_sewa);

                        if ($stmt->execute()) {
                            if (empty($error_message)) {
                                $success_message = "Data sewa berhasil diupdate!";
                            }

                            // Logic to update old unit status if unit was changed
                            if ($id_unit !== $old_unit_id) {
                                // Check if old unit still has any active rentals
                                $check_old_unit_rentals = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE() AND id_sewa != ?");
                                if ($check_old_unit_rentals) {
                                    $check_old_unit_rentals->bind_param("ss", $old_unit_id, $id_sewa);
                                    $check_old_unit_rentals->execute();
                                    $old_unit_active_count = $check_old_unit_rentals->get_result()->fetch_row()[0];
                                    $check_old_unit_rentals->close();

                                    if ($old_unit_active_count == 0) {
                                        // If no other active rentals, set old unit to 'Tersedia' (unless 'Dalam Perbaikan')
                                        $get_old_unit_current_status_stmt = $conn->prepare("SELECT status FROM unit WHERE id_unit = ?");
                                        if ($get_old_unit_current_status_stmt) {
                                            $get_old_unit_current_status_stmt->bind_param("s", $old_unit_id);
                                            $get_old_unit_current_status_stmt->execute();
                                            $old_unit_current_status = $get_old_unit_current_status_stmt->get_result()->fetch_assoc()['status'];
                                            $get_old_unit_current_status_stmt->close();

                                            if ($old_unit_current_status !== 'Dalam Perbaikan') {
                                                $update_old_unit_status = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                                if ($update_old_unit_status) {
                                                    $update_old_unit_status->bind_param("s", $old_unit_id);
                                                    $update_old_unit_status->execute();
                                                    $update_old_unit_status->close();
                                                } else {
                                                    error_log("Failed to prepare old unit status update: " . mysqli_error($conn));
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    error_log("Failed to prepare check old unit rentals: " . mysqli_error($conn));
                                }
                            }

                            // Logic to update new unit status based on the current sewa status
                            $new_unit_status_value = 'Tersedia'; // Default assumption
                            if ($status === 'Aktif') {
                                $new_unit_status_value = 'Terisi';
                            } elseif ($status === 'Dalam Perbaikan') {
                                $new_unit_status_value = 'Dalam Perbaikan'; // Should not happen directly from sewa form
                            }

                            // Final check on new unit: if it has any active rentals, it should be 'Terisi'
                            // This covers cases where new sewa is not active, but another sewa on the unit is
                            if ($new_unit_status_value === 'Tersedia') { // Only check if current determined status is Tersedia
                                $check_current_unit_for_active_rentals = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE() AND id_sewa != ?");
                                if ($check_current_unit_for_active_rentals) {
                                    $check_current_unit_for_active_rentals->bind_param("ss", $id_unit, $id_sewa);
                                    $check_current_unit_for_active_rentals->execute();
                                    $current_unit_active_count = $check_current_unit_for_active_rentals->get_result()->fetch_row()[0];
                                    $check_current_unit_for_active_rentals->close();

                                    if ($current_unit_active_count > 0) {
                                        $new_unit_status_value = 'Terisi'; // Override to 'Terisi' if other active sewa exists
                                    }
                                } else {
                                    error_log("Failed to prepare check current unit rentals: " . mysqli_error($conn));
                                }
                            }

                            // Update the new unit's status
                            $update_new_unit_status = $conn->prepare("UPDATE unit SET status = ? WHERE id_unit = ?");
                            if ($update_new_unit_status) {
                                $update_new_unit_status->bind_param("ss", $new_unit_status_value, $id_unit);
                                $update_new_unit_status->execute();
                                $update_new_unit_status->close();
                            } else {
                                error_log("Failed to prepare new unit status update: " . mysqli_error($conn));
                            }
                        } else {
                            $error_message = "Gagal mengupdate data sewa: " . $stmt->error;
                            error_log("Failed to execute update sewa: " . $stmt->error);
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement edit data sewa: " . mysqli_error($conn);
                        error_log("Failed to prepare edit sewa statement: " . mysqli_error($conn));
                    }
                }
                break;

            case 'delete':
                $id_sewa = $_POST['id_sewa'];

                $sewa_info = null;
                $get_sewa_info_stmt = $conn->prepare("SELECT id_unit, status FROM sewa WHERE id_sewa = ?");
                if ($get_sewa_info_stmt) {
                    $get_sewa_info_stmt->bind_param("s", $id_sewa);
                    $get_sewa_info_stmt->execute();
                    $sewa_info = $get_sewa_info_stmt->get_result()->fetch_assoc();
                    $get_sewa_info_stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan query info sewa sebelum hapus: " . mysqli_error($conn);
                    error_log("Failed to prepare sewa info fetch before delete: " . mysqli_error($conn));
                    break;
                }

                $stmt = $conn->prepare("DELETE FROM sewa WHERE id_sewa = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_sewa);

                    if ($stmt->execute()) {
                        $success_message = "Data sewa berhasil dihapus!";
                        if ($sewa_info) {
                            // Check if the unit of the deleted sewa has any other active rentals
                            $check_other_rentals = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                            if ($check_other_rentals) {
                                $check_other_rentals->bind_param("s", $sewa_info['id_unit']);
                                $check_other_rentals->execute();
                                $active_rentals_count = $check_other_rentals->get_result()->fetch_row()[0];
                                $check_other_rentals->close();

                                if ($active_rentals_count == 0) {
                                    // If no other active rentals, set unit to 'Tersedia' (unless 'Dalam Perbaikan')
                                    $get_unit_current_status_stmt = $conn->prepare("SELECT status FROM unit WHERE id_unit = ?");
                                    if ($get_unit_current_status_stmt) {
                                        $get_unit_current_status_stmt->bind_param("s", $sewa_info['id_unit']);
                                        $get_unit_current_status_stmt->execute();
                                        $unit_current_status = $get_unit_current_status_stmt->get_result()->fetch_assoc()['status'];
                                        $get_unit_current_status_stmt->close();

                                        if ($unit_current_status !== 'Dalam Perbaikan') {
                                            $update_unit_status = $conn->prepare("UPDATE unit SET status = 'Tersedia' WHERE id_unit = ?");
                                            if ($update_unit_status) {
                                                $update_unit_status->bind_param("s", $sewa_info['id_unit']);
                                                $update_unit_status->execute();
                                                $update_unit_status->close();
                                            } else {
                                                error_log("Failed to prepare unit status update after delete: " . mysqli_error($conn));
                                            }
                                        }
                                    }
                                }
                            } else {
                                error_log("Failed to prepare other rentals check after delete: " . mysqli_error($conn));
                            }
                        }
                    } else {
                        $error_message = "Gagal menghapus data sewa: " . $stmt->error;
                        error_log("Failed to execute delete sewa: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement hapus data sewa: " . mysqli_error($conn);
                    error_log("Failed to prepare delete sewa statement: " . mysqli_error($conn));
                }
                break;
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_unit = isset($_GET['filter_unit']) ? trim($_GET['filter_unit']) : '';
$filter_penyewa = isset($_GET['filter_penyewa']) ? trim($_GET['filter_penyewa']) : ''; // Sudah ada


$query = "
    SELECT s.*, u.nomor_unit, b.nama_bangunan, p.nama as nama_penyewa
    FROM sewa s
    LEFT JOIN unit u ON s.id_unit = u.id_unit
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    LEFT JOIN penyewa p ON s.id_penyewa = p.id_penyewa
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (u.nomor_unit LIKE ? OR b.nama_bangunan LIKE ? OR p.nama LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

if (!empty($filter_status)) {
    $query .= " AND s.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if (!empty($filter_unit)) {
    $query .= " AND s.id_unit = ?";
    $params[] = $filter_unit;
    $types .= "s";
}

if (!empty($filter_penyewa)) { // Cek jika $filter_penyewa tidak kosong
    $query .= " AND s.id_penyewa = ?";
    $params[] = $filter_penyewa;
    $types .= "s";
}

$query .= " ORDER BY s.tanggal_mulai DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $sewa_list = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $sewa_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data sewa: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data sewa: " . mysqli_error($conn);
}


$unit_stmt = $conn->prepare("
    SELECT u.id_unit, u.nomor_unit, b.nama_bangunan, u.status as unit_status
    FROM unit u
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    ORDER BY b.nama_bangunan, u.nomor_unit
");
$unit_options = [];
if ($unit_stmt) {
    if ($unit_stmt->execute()) {
        $unit_result = $unit_stmt->get_result();
        $unit_options = $unit_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data unit untuk dropdown: " . $unit_stmt->error;
    }
    $unit_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query unit untuk dropdown: " . mysqli_error($conn);
}


$penyewa_stmt = $conn->prepare("SELECT id_penyewa, nama FROM penyewa ORDER BY nama");
$penyewa_options = [];
if ($penyewa_stmt) {
    if ($penyewa_stmt->execute()) {
        $penyewa_result = $penyewa_stmt->get_result();
        $penyewa_options = $penyewa_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data penyewa untuk dropdown: " . $penyewa_stmt->error;
    }
    $penyewa_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query penyewa untuk dropdown: " . mysqli_error($conn);
}


$stats_query = "
    SELECT
        COUNT(DISTINCT s.id_sewa) as total_sewa,
        SUM(CASE WHEN s.status = 'Aktif' AND s.tanggal_selesai >= CURDATE() THEN 1 ELSE 0 END) as sewa_aktif,
        SUM(CASE WHEN s.status = 'Selesai' OR (s.status = 'Aktif' AND s.tanggal_selesai < CURDATE()) THEN 1 ELSE 0 END) as sewa_selesai,
        SUM(CASE WHEN s.status = 'Dibatalkan' THEN 1 ELSE 0 END) as sewa_dibatalkan,
        SUM(CASE WHEN s.status = 'Menunggu Pembayaran' THEN 1 ELSE 0 END) as sewa_menunggu_pembayaran
    FROM sewa s
";

$stats_stmt = $conn->prepare($stats_query);
$stats = [
    'total_sewa' => 0,
    'sewa_aktif' => 0,
    'sewa_selesai' => 0,
    'sewa_dibatalkan' => 0,
    'sewa_menunggu_pembayaran' => 0
];

if ($stats_stmt) {
    if ($stats_stmt->execute()) {
        $stats_result = $stats_stmt->get_result();
        $stats_fetched = $stats_result->fetch_assoc();
        $stats = array_merge($stats, $stats_fetched);
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
    <title>Manajemen Sewa - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/sewa.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-file-contract"></i> Manajemen Sewa - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Sewa
                </div>
                <h1>Manajemen Sewa</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Data Sewa
            </button>
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
                <div class="number"><?php echo number_format($stats['total_sewa']); ?></div>
                <div class="label">Total Sewa</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['sewa_aktif']); ?></div>
                <div class="label">Sewa Aktif</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['sewa_selesai']); ?></div>
                <div class="label">Sewa Selesai</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['sewa_dibatalkan']); ?></div>
                <div class="label">Sewa Dibatalkan</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['sewa_menunggu_pembayaran']); ?></div>
                <div class="label">Menunggu Pembayaran</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" id="filterForm" class="search-form-container">
                    <div class="search-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Cari unit, bangunan, atau penyewa..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <select name="filter_status" id="filter_status"
                            onchange="document.getElementById('filterForm').submit()">
                            <option value="">Semua Status</option>
                            <option value="Aktif" <?php echo ($filter_status === 'Aktif') ? 'selected' : ''; ?>>Aktif
                            </option>
                            <option value="Selesai" <?php echo ($filter_status === 'Selesai') ? 'selected' : ''; ?>>
                                Selesai
                            </option>
                            <option value="Dibatalkan" <?php echo ($filter_status === 'Dibatalkan') ? 'selected' : ''; ?>>
                                Dibatalkan</option>
                            <option value="Menunggu Pembayaran" <?php echo ($filter_status === 'Menunggu Pembayaran') ? 'selected' : ''; ?>>
                                Menunggu Pembayaran</option>
                        </select>
                        <select name="filter_unit" id="filter_unit"
                            onchange="document.getElementById('filterForm').submit()">
                            <option value="">Semua Unit</option>
                            <?php foreach ($unit_options as $unit): ?>
                                <option value="<?php echo htmlspecialchars($unit['id_unit']); ?>" <?php echo ($filter_unit === $unit['id_unit']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unit['nomor_unit'] . ' (' . ($unit['nama_bangunan'] ?? 'Tidak Ditemukan') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_penyewa" id="filter_penyewa"
                            onchange="document.getElementById('filterForm').submit()">
                            <option value="" <?php echo ($filter_penyewa == '') ? 'selected' : ''; ?>>Semua Penyewa</option>
                            <?php foreach ($penyewa_options as $penyewa): ?>
                                <option value="<?php echo htmlspecialchars($penyewa['id_penyewa']); ?>" <?php echo ($filter_penyewa == $penyewa['id_penyewa']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($penyewa['nama']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search) || !empty($filter_status) || !empty($filter_unit) || !empty($filter_penyewa)): ?>
                    Menampilkan <?php echo count($sewa_list); ?> hasil
                <?php else: ?>
                    Total <?php echo count($sewa_list); ?> data sewa terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Sewa Terdaftar</span>
            </div>
            <?php if (empty($sewa_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-file-contract"></i>
                    <?php if (!empty($search) || !empty($filter_status) || !empty($filter_unit) || !empty($filter_penyewa)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada data sewa yang cocok dengan kriteria pencarian/filter Anda. Coba gunakan kata kunci atau
                            filter yang berbeda.</p>
                    <?php else: ?>
                        <h3>Belum ada data sewa</h3>
                        <p>Sistem belum memiliki data sewa yang terdaftar. Klik tombol "Tambah Data Sewa" untuk menambahkan data
                            sewa pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-door-open"></i> Unit (Bangunan)</th>
                                <th><i class="fas fa-user-friends"></i> Penyewa</th>
                                <th><i class="fas fa-calendar-alt"></i> Tanggal Mulai</th>
                                <th><i class="fas fa-calendar-alt"></i> Tanggal Selesai</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sewa_list as $sewa): ?>
                                <tr>
                                    <td class="data-cell primary">
                                        <strong><?php echo htmlspecialchars($sewa['nomor_unit'] ?? 'Unit Tidak Ditemukan'); ?></strong>
                                        (<?php echo htmlspecialchars($sewa['nama_bangunan'] ?? 'Bangunan Tidak Ditemukan'); ?>)
                                    </td>
                                    <td class="data-cell info">
                                        <?php echo htmlspecialchars($sewa['nama_penyewa'] ?? 'Penyewa Tidak Ditemukan'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars(date('d F Y', strtotime($sewa['tanggal_mulai']))); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars(date('d F Y', strtotime($sewa['tanggal_selesai']))); ?>
                                    </td>
                                    <td
                                        class="data-cell status-<?php echo strtolower(str_replace(' ', '-', $sewa['status'])); ?>">
                                        <strong><?php echo htmlspecialchars($sewa['status']); ?></strong>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit"
                                                onclick="openEditModal('<?php echo htmlspecialchars($sewa['id_sewa']); ?>', '<?php echo htmlspecialchars($sewa['id_unit'] ?? ''); ?>', '<?php echo htmlspecialchars($sewa['id_penyewa'] ?? ''); ?>', '<?php echo htmlspecialchars($sewa['tanggal_mulai']); ?>', '<?php echo htmlspecialchars($sewa['tanggal_selesai']); ?>', '<?php echo htmlspecialchars($sewa['status']); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo htmlspecialchars($sewa['id_sewa']); ?>', 'Sewa Unit <?php echo htmlspecialchars($sewa['nomor_unit'] ?? ''); ?> oleh <?php echo htmlspecialchars($sewa['nama_penyewa'] ?? ''); ?>')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                            <a href="#" class="btn-edit"
                                                style="background: #332D56; color: white; text-decoration: none; padding: 8px 16px; border-radius: 8px; font-size: 0.8em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;"
                                                onclick="openAddTransaksiModal('<?php echo htmlspecialchars($sewa['id_sewa']); ?>')">
                                                <i class="fas fa-money-bill-wave"></i> Transaksi
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

    <div id="sewaModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Tambah Data Sewa Baru</h3>
                <button class="close-btn" onclick="closeModal('sewaModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="sewaForm" method="POST"> <input type="hidden" id="formAction" name="action" value="add">
                    <input type="hidden" id="formIdSewa" name="id_sewa" value="">

                    <div class="form-group">
                        <label for="id_unit"><i class="fas fa-door-open"></i> Unit *</label>
                        <select id="id_unit" name="id_unit" required>
                            <option value="">Pilih Unit</option>
                            <?php foreach ($unit_options as $unit): ?>
                                <option value="<?php echo htmlspecialchars($unit['id_unit']); ?>"
                                    data-status="<?php echo htmlspecialchars($unit['unit_status']); ?>">
                                    <?php echo htmlspecialchars($unit['nomor_unit'] . ' (' . ($unit['nama_bangunan'] ?? 'Tidak Ditemukan') . ')'); ?>
                                    <?php if ($unit['unit_status'] == 'Terisi' || $unit['unit_status'] == 'Dalam Perbaikan')
                                        echo ' (' . htmlspecialchars($unit['unit_status']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="id_penyewa"><i class="fas fa-user-friends"></i> Penyewa *</label>
                        <select id="id_penyewa" name="id_penyewa" required>
                            <option value="">Pilih Penyewa</option>
                            <?php foreach ($penyewa_options as $penyewa): ?>
                                <option value="<?php echo htmlspecialchars($penyewa['id_penyewa']); ?>">
                                    <?php echo htmlspecialchars($penyewa['nama']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tanggal_mulai"><i class="fas fa-calendar-alt"></i> Tanggal Mulai *</label>
                        <input type="date" id="tanggal_mulai" name="tanggal_mulai" required>
                    </div>

                    <div class="form-group">
                        <label for="tanggal_selesai"><i class="fas fa-calendar-alt"></i> Tanggal Selesai *</label>
                        <input type="date" id="tanggal_selesai" name="tanggal_selesai" required>
                    </div>

                    <div class="form-group">
                        <label for="status">Status *</label>
                        <select id="status" name="status" required>
                            <option value="Menunggu Pembayaran">Menunggu Pembayaran</option>
                            <option value="Aktif">Aktif</option>
                            <option value="Dibatalkan">Dibatalkan</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Simpan Data Sewa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 480px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #C08B8B, #a67373);">
                <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Penghapusan</h3>
                <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-trash-alt" style="font-size: 3em; color: #C08B8B; margin-bottom: 20px;"></i>
                    <p style="font-size: 1.1em; margin-bottom: 10px; color: #333;">Apakah Anda yakin ingin menghapus
                        data sewa:</p>
                    <p id="deleteSewaName"
                        style="font-weight: 600; color: #C08B8B; font-size: 1.2em; margin-bottom: 20px;"></p>
                    <div
                        style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #856404; font-size: 0.9em; margin: 0;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 5px;"></i>
                            <strong>Perhatian:</strong> Semua transaksi pembayaran yang terkait dengan sewa ini juga
                            akan
                            dihapus.
                        </p>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" onclick="closeModal('deleteModal')"
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
        <input type="hidden" id="delete_id" name="id_sewa">
    </form>

    <script>
        let deleteSewaId = '';
        let deleteSewaName = '';

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Data Sewa Baru';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formIdSewa').value = '';
            document.getElementById('sewaForm').reset();
            document.getElementById('tanggal_mulai').valueAsDate = new Date();
            let oneYearFromNow = new Date();
            oneYearFromNow.setFullYear(oneYearFromNow.getFullYear() + 1);
            document.getElementById('tanggal_selesai').valueAsDate = oneYearFromNow;
            document.getElementById('status').value = 'Menunggu Pembayaran';

            const unitSelect = document.getElementById('id_unit');
            Array.from(unitSelect.options).forEach(option => {
                if (option.value === "") {
                    option.disabled = false;
                    return;
                }
                const unitStatus = option.dataset.status;
                if (unitStatus === 'Terisi' || unitStatus === 'Dalam Perbaikan') {
                    option.disabled = true;
                    option.title = `Unit ini sudah ${unitStatus.toLowerCase()}`;
                } else {
                    option.disabled = false;
                    option.title = '';
                }
            });

            document.getElementById('sewaModal').style.display = 'block';
        }

        function openEditModal(id, idUnit, idPenyewa, tanggalMulai, tanggalSelesai, status) {
            document.getElementById('modalTitle').textContent = 'Edit Data Sewa';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formIdSewa').value = id;
            document.getElementById('id_unit').value = idUnit;
            document.getElementById('id_penyewa').value = idPenyewa;
            document.getElementById('tanggal_mulai').value = tanggalMulai;
            document.getElementById('tanggal_selesai').value = tanggalSelesai;
            document.getElementById('status').value = status;

            const statusSelect = document.getElementById('status');
            let selesaiOption = statusSelect.querySelector('option[value="Selesai"]');
            if (!selesaiOption) {
                selesaiOption = document.createElement('option');
                selesaiOption.value = 'Selesai';
                selesaiOption.textContent = 'Selesai';
                statusSelect.appendChild(selesaiOption);
            }

            const unitSelect = document.getElementById('id_unit');
            const currentUnitId = idUnit;

            Array.from(unitSelect.options).forEach(option => {
                option.disabled = false;
                option.title = '';
            });

            Array.from(unitSelect.options).forEach(option => {
                if (option.value === "" || option.value === currentUnitId) {
                    return;
                }
                const unitStatus = option.dataset.status;
                if (unitStatus === 'Terisi' || unitStatus === 'Dalam Perbaikan') {
                    option.disabled = true;
                    option.title = `Unit ini sudah ${unitStatus.toLowerCase()}`;
                }
            });

            document.getElementById('sewaModal').style.display = 'block';
        }


        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            const statusSelect = document.getElementById('status');
            const selesaiOption = statusSelect.querySelector('option[value="Selesai"]');
            if (selesaiOption) {
                selesaiOption.remove();
            }
        }

        function confirmDelete(id, nama) {
            deleteSewaId = id;
            deleteSewaName = nama;
            document.getElementById('deleteSewaName').textContent = nama;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function executeDelete() {
            document.getElementById('delete_id').value = deleteSewaId;
            document.getElementById('deleteForm').submit();
        }

        window.onclick = function (event) {
            const modals = ['sewaModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        }

        function openAddTransaksiModal(idSewa) {
            window.location.href = 'transaksi.php?action=add&id_sewa=' + idSewa;
        }

        function resetFilters() {
            window.location.href = 'sewa.php';
        }


        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.closest('form').submit();
                }
            });
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openAddModal();
            }

            if (e.key === 'Escape') {
                closeModal('sewaModal');
                closeModal('deleteModal');
            }
        });

        document.addEventListener('DOMContentLoaded', function () {
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(function (row) {
                row.addEventListener('mouseenter', function () {
                    this.style.transform = 'translateX(3px)';
                });

                row.addEventListener('mouseleave', function () {
                    this.style.transform = 'translateX(0)';
                });
            });

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