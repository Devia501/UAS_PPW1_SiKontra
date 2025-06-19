<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $id_bangunan = trim($_POST['id_bangunan']);
                $nomor_unit = trim($_POST['nomor_unit']);
                $tipe = trim($_POST['tipe']);
                $harga_sewa = trim($_POST['harga_sewa']);
                $status = trim($_POST['status']);

                if (empty($id_bangunan) || empty($nomor_unit) || empty($tipe) || empty($harga_sewa) || empty($status)) {
                    $error_message = "Semua field wajib diisi!";
                } elseif (!is_numeric($harga_sewa) || $harga_sewa <= 0) {
                    $error_message = "Harga sewa harus berupa angka positif!";
                } else {
                    $check_duplicate_stmt = $conn->prepare("SELECT COUNT(*) FROM unit WHERE id_bangunan = ? AND nomor_unit = ?");
                    if ($check_duplicate_stmt) {
                        $check_duplicate_stmt->bind_param("ss", $id_bangunan, $nomor_unit);
                        $check_duplicate_stmt->execute();
                        $result_duplicate = $check_duplicate_stmt->get_result();
                        $row_duplicate = $result_duplicate->fetch_row();
                        if ($row_duplicate[0] > 0) {
                            $error_message = "Nomor unit '$nomor_unit' sudah ada di bangunan ini!";
                            $check_duplicate_stmt->close();
                            break;
                        }
                        $check_duplicate_stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan pengecekan duplikasi unit: " . mysqli_error($conn);
                        break;
                    }

                    $id_unit = generateUUID();
                    $stmt = $conn->prepare("INSERT INTO unit (id_unit, id_bangunan, nomor_unit, tipe, harga_sewa, status) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $id_unit, $id_bangunan, $nomor_unit, $tipe, $harga_sewa, $status);
                        if ($stmt->execute()) {
                            $success_message = "Unit berhasil ditambahkan!";
                        } else {
                            $error_message = "Gagal menambahkan unit: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement tambah unit: " . mysqli_error($conn);
                    }
                }
                break;

            case 'edit':
                $id_unit = trim($_POST['id_unit']);
                $id_bangunan = trim($_POST['id_bangunan']);
                $nomor_unit = trim($_POST['nomor_unit']);
                $tipe = trim($_POST['tipe']);
                $harga_sewa = trim($_POST['harga_sewa']);
                $status = trim($_POST['status']);

                if (empty($id_unit) || empty($id_bangunan) || empty($nomor_unit) || empty($tipe) || empty($harga_sewa) || empty($status)) {
                    $error_message = "Semua field wajib diisi!";
                } elseif (!is_numeric($harga_sewa) || $harga_sewa <= 0) {
                    $error_message = "Harga sewa harus berupa angka positif!";
                } else {
                    $check_duplicate_stmt = $conn->prepare("SELECT COUNT(*) FROM unit WHERE id_bangunan = ? AND nomor_unit = ? AND id_unit != ?");
                    if ($check_duplicate_stmt) {
                        $check_duplicate_stmt->bind_param("sss", $id_bangunan, $nomor_unit, $id_unit);
                        $check_duplicate_stmt->execute();
                        $result_duplicate = $check_duplicate_stmt->get_result();
                        $row_duplicate = $result_duplicate->fetch_row();
                        if ($row_duplicate[0] > 0) {
                            $error_message = "Nomor unit '$nomor_unit' sudah ada di bangunan ini!";
                            $check_duplicate_stmt->close();
                            break;
                        }
                        $check_duplicate_stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan pengecekan duplikasi unit saat edit: " . mysqli_error($conn);
                        break;
                    }

                    if ($status === 'Tersedia') {
                        $check_active_sewa_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                        if ($check_active_sewa_stmt) {
                            $check_active_sewa_stmt->bind_param("s", $id_unit);
                            $check_active_sewa_stmt->execute();
                            $active_sewa_count = $check_active_sewa_stmt->get_result()->fetch_row()[0];
                            if ($active_sewa_count > 0) {
                                $error_message = "Unit ini tidak dapat diubah statusnya menjadi 'Tersedia' karena masih memiliki kontrak sewa yang aktif.";
                                $check_active_sewa_stmt->close();
                                break;
                            }
                            $check_active_sewa_stmt->close();
                        } else {
                            $error_message = "Gagal menyiapkan pengecekan sewa aktif unit: " . mysqli_error($conn);
                            break;
                        }
                    }

                    $stmt = $conn->prepare("UPDATE unit SET id_bangunan = ?, nomor_unit = ?, tipe = ?, harga_sewa = ?, status = ? WHERE id_unit = ?");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $id_bangunan, $nomor_unit, $tipe, $harga_sewa, $status, $id_unit);
                        if ($stmt->execute()) {
                            $success_message = "Data unit berhasil diupdate!";
                        } else {
                            $error_message = "Gagal mengupdate unit: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement edit unit: " . mysqli_error($conn);
                    }
                }
                break;

            case 'delete':
                $id_unit = preg_replace('/[^a-f0-9\-]/', '', trim($_POST['id_unit']));

                $current_unit_status = '';
                $check_active_rentals_on_delete_stmt = null;
                if (!empty($id_unit)) {
                    $check_active_rentals_on_delete_stmt = $conn->prepare("SELECT COUNT(*) FROM sewa WHERE id_unit = ? AND status = 'Aktif' AND tanggal_selesai >= CURDATE()");
                    if ($check_active_rentals_on_delete_stmt) {
                        $check_active_rentals_on_delete_stmt->bind_param("s", $id_unit);
                        $check_active_rentals_on_delete_stmt->execute();
                        $active_rentals_count_on_delete = $check_active_rentals_on_delete_stmt->get_result()->fetch_row()[0];
                        $check_active_rentals_on_delete_stmt->close();

                        if ($active_rentals_count_on_delete > 0) {
                            $error_message = "Gagal menghapus unit: Unit ini tidak dapat dihapus karena masih memiliki kontrak sewa yang aktif.";
                            break;
                        }
                    } else {
                        $error_message = "Gagal menyiapkan pengecekan sewa aktif unit sebelum hapus: " . mysqli_error($conn);
                        break;
                    }

                    $get_status_stmt = $conn->prepare("SELECT status FROM unit WHERE id_unit = ?");
                    if ($get_status_stmt) {
                        $get_status_stmt->bind_param("s", $id_unit);
                        $get_status_stmt->execute();
                        $status_result = $get_status_stmt->get_result();
                        if ($row = $status_result->fetch_assoc()) {
                            $current_unit_status = $row['status'];
                        } else {
                            $error_message = "Gagal menghapus unit: Unit dengan ID '$id_unit' tidak ditemukan saat cek status.";
                            break;
                        }
                        $get_status_stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement untuk cek status unit: " . mysqli_error($conn);
                        break;
                    }
                } else {
                    $error_message = "Gagal menghapus unit: ID unit kosong atau tidak valid.";
                    break;
                }

                $stmt = $conn->prepare("DELETE FROM unit WHERE id_unit = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_unit);
                    if ($stmt->execute()) {
                        if (mysqli_affected_rows($conn) > 0) {
                            $success_message = "Unit berhasil dihapus!";
                        } else {
                            $error_message = "Gagal menghapus unit: Unit dengan ID '$id_unit' tidak ditemukan atau sudah terhapus.";
                        }
                    } else {
                        $error_message = "Gagal menghapus unit: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement hapus unit: " . mysqli_error($conn);
                }
                break;
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_status = isset($_GET['filter_status']) ? trim($_GET['filter_status']) : '';
$filter_bangunan = isset($_GET['filter_bangunan']) ? trim($_GET['filter_bangunan']) : '';


$query = "
    SELECT u.*, b.nama_bangunan,
           (SELECT p.nama FROM sewa s JOIN penyewa p ON s.id_penyewa = p.id_penyewa WHERE s.id_unit = u.id_unit AND s.status = 'Aktif' AND s.tanggal_selesai >= CURDATE() LIMIT 1) as nama_penyewa_aktif
    FROM unit u
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (u.nomor_unit LIKE ? OR u.tipe LIKE ? OR b.nama_bangunan LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sss";
}

if (!empty($filter_status)) {
    $query .= " AND u.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if (!empty($filter_bangunan)) {
    $query .= " AND u.id_bangunan = ?";
    $params[] = $filter_bangunan;
    $types .= "s";
}

$query .= " ORDER BY b.nama_bangunan, u.nomor_unit";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $unit_list = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $unit_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data unit: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data unit: " . mysqli_error($conn);
}


$bangunan_stmt = $conn->prepare("SELECT id_bangunan, nama_bangunan FROM bangunan ORDER BY nama_bangunan");
$bangunan_options = [];
if ($bangunan_stmt) {
    if ($bangunan_stmt->execute()) {
        $bangunan_result = $bangunan_stmt->get_result();
        $bangunan_options = $bangunan_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data bangunan untuk dropdown: " . $bangunan_stmt->error;
    }
    $bangunan_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query bangunan untuk dropdown: " . mysqli_error($conn);
}


$stats_query = "
    SELECT
        COUNT(DISTINCT u.id_unit) as total_unit,
        SUM(CASE WHEN u.status = 'Tersedia' THEN 1 ELSE 0 END) as unit_tersedia,
        SUM(CASE WHEN u.status = 'Terisi' THEN 1 ELSE 0 END) as unit_terisi,
        SUM(CASE WHEN u.status = 'Dalam Perbaikan' THEN 1 ELSE 0 END) as unit_perbaikan
    FROM unit u
";

$stats_stmt = $conn->prepare($stats_query);
$stats = [
    'total_unit' => 0,
    'unit_tersedia' => 0,
    'unit_terisi' => 0,
    'unit_perbaikan' => 0
];

if ($stats_stmt) {
    if ($stats_stmt->execute()) {
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil statistik unit: " . $stats_stmt->error;
    }
    $stats_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query statistik unit: " . mysqli_error($conn);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Unit - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/unit.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-door-open"></i> Manajemen Unit - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Unit
                </div>
                <h1>Manajemen Unit</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Unit Baru
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
                <div class="number"><?php echo number_format($stats['total_unit']); ?></div>
                <div class="label">Total Unit</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['unit_tersedia']); ?></div>
                <div class="label">Unit Tersedia</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['unit_terisi']); ?></div>
                <div class="label">Unit Terisi</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['unit_perbaikan']); ?></div>
                <div class="label">Unit Dalam Perbaikan</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" id="filterForm" class="search-form-container">
                    <div class="search-input-group">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Cari nomor, tipe, atau bangunan..."
                            value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <select name="filter_status" id="filter_status" onchange="this.form.submit()">
                            <option value="">Semua Status</option>
                            <option value="Tersedia" <?php echo ($filter_status === 'Tersedia') ? 'selected' : ''; ?>>
                                Tersedia
                            </option>
                            <option value="Terisi" <?php echo ($filter_status === 'Terisi') ? 'selected' : ''; ?>>Terisi
                            </option>
                            <option value="Dalam Perbaikan" <?php echo ($filter_status === 'Dalam Perbaikan') ? 'selected' : ''; ?>>Dalam Perbaikan</option>
                        </select>
                        <select name="filter_bangunan" id="filter_bangunan" onchange="this.form.submit()">
                            <option value="">Semua Bangunan</option>
                            <?php foreach ($bangunan_options as $bangunan): ?>
                                <option value="<?php echo htmlspecialchars($bangunan['id_bangunan']); ?>" <?php echo ($filter_bangunan === $bangunan['id_bangunan']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bangunan['nama_bangunan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
                    </div>
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search) || !empty($filter_status) || !empty($filter_bangunan)): ?>
                    Menampilkan <?php echo count($unit_list); ?> hasil
                <?php else: ?>
                    Total <?php echo count($unit_list); ?> unit terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Unit Terdaftar</span>
            </div>
            <?php if (empty($unit_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-door-open"></i>
                    <?php if (!empty($search) || !empty($filter_status) || !empty($filter_bangunan)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada unit yang cocok dengan kriteria pencarian/filter Anda. Coba gunakan kata kunci atau filter
                            yang berbeda.</p>
                    <?php else: ?>
                        <h3>Belum ada data unit</h3>
                        <p>Sistem belum memiliki data unit yang terdaftar. Klik tombol "Tambah Unit Baru" untuk menambahkan data
                            unit pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-building"></i> Bangunan</th>
                                <th><i class="fas fa-door-closed"></i> Nomor Unit</th>
                                <th><i class="fas fa-tags"></i> Tipe</th>
                                <th><i class="fas fa-dollar-sign"></i> Harga Sewa</th>
                                <th><i class="fas fa-info-circle"></i> Status</th>
                                <th><i class="fas fa-user-friends"></i> Penyewa Aktif</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unit_list as $unit): ?>
                                <tr>
                                    <td class="data-cell primary">
                                        <strong><?php echo htmlspecialchars($unit['nama_bangunan'] ?? 'Bangunan Tidak Ditemukan'); ?></strong>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($unit['nomor_unit']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($unit['tipe']); ?>
                                    </td>
                                    <td class="data-cell">
                                        Rp <?php echo number_format($unit['harga_sewa'], 0, ',', '.'); ?>
                                    </td>
                                    <td
                                        class="data-cell status-<?php echo strtolower(str_replace(' ', '-', $unit['status'])); ?>">
                                        <strong><?php echo htmlspecialchars($unit['status']); ?></strong>
                                    </td>
                                    <td class="data-cell tenant">
                                        <?php echo htmlspecialchars($unit['nama_penyewa_aktif'] ?? 'Tidak Ada'); ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit"
                                                onclick="openEditModal('<?php echo htmlspecialchars($unit['id_unit']); ?>', '<?php echo htmlspecialchars($unit['id_bangunan'] ?? ''); ?>', '<?php echo htmlspecialchars($unit['nomor_unit'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($unit['tipe'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($unit['harga_sewa'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($unit['status'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo htmlspecialchars($unit['id_unit']); ?>', 'Unit <?php echo htmlspecialchars($unit['nomor_unit'], ENT_QUOTES); ?> (<?php echo htmlspecialchars($unit['nama_bangunan'] ?? 'Tidak Ditemukan'); ?>)')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
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

    <div id="unitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Tambah Unit Baru</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="unitForm" method="POST">
                    <input type="hidden" id="formAction" name="action" value="add">
                    <input type="hidden" id="formIdUnit" name="id_unit" value="">

                    <div class="form-group">
                        <label for="id_bangunan"><i class="fas fa-building"></i> Bangunan *</label>
                        <select id="id_bangunan" name="id_bangunan" required>
                            <option value="">Pilih Bangunan</option>
                            <?php foreach ($bangunan_options as $bangunan): ?>
                                <option value="<?php echo htmlspecialchars($bangunan['id_bangunan']); ?>">
                                    <?php echo htmlspecialchars($bangunan['nama_bangunan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="nomor_unit"><i class="fas fa-door-closed"></i> Nomor Unit *</label>
                        <input type="text" id="nomor_unit" name="nomor_unit" required
                            placeholder="Contoh: A-01, No. 101">
                    </div>

                    <div class="form-group">
                        <label for="tipe"><i class="fas fa-tags"></i> Tipe Unit *</label>
                        <input type="text" id="tipe" name="tipe" required placeholder="Contoh: Rumah, 2 Kamar Tidur">
                    </div>

                    <div class="form-group">
                        <label for="harga_sewa"><i class="fas fa-dollar-sign"></i> Harga Sewa (Per Bulan) *</label>
                        <input type="number" id="harga_sewa" name="harga_sewa" step="0.01" min="0" required
                            placeholder="Contoh: 1500000.00">
                    </div>

                    <div class="form-group">
                        <label for="status"><i class="fas fa-info-circle"></i> Status *</label>
                        <select id="status" name="status" required>
                            <option value="Tersedia">Tersedia</option>
                            <option value="Terisi">Terisi</option>
                            <option value="Dalam Perbaikan">Dalam Perbaikan</option>
                        </select>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Simpan Data Unit
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #C08B8B, #a67373);">
                <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Penghapusan</h3>
                <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-trash-alt" style="font-size: 3em; color: #C08B8B; margin-bottom: 20px;"></i>
                    <h4 style="color: #332D56; margin-bottom: 15px;">Apakah Anda yakin?</h4>
                    <p style="color: #666; margin-bottom: 25px; line-height: 1.6;">
                        Data <strong id="deleteUnitName"></strong> akan dihapus secara permanen dari sistem.
                        <br>
                        <small style="color: #dc3545; font-weight: bold;">Semua kontrak sewa dan transaksi terkait yang
                            terhubung dengan unit ini akan kehilangan referensi ke unit.</small>
                    </p>
                </div>

                <form id="deleteForm" method="POST" style="display: flex; gap: 15px;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" id="deleteIdUnit" name="id_unit" value="">

                    <button type="button" onclick="closeDeleteModal()"
                        style="flex: 1; padding: 12px; border: 2px solid #dee2e6; background: white; color: #666; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                        <i class="fas fa-times"></i> Batal
                    </button>

                    <button type="submit"
                        style="flex: 1; padding: 12px; border: none; background: linear-gradient(135deg, #C08B8B, #a67373); color: white; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                        <i class="fas fa-trash"></i> Ya, Hapus
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        let deleteUnitId = '';
        let deleteUnitName = '';

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Unit Baru';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formIdUnit').value = '';
            document.getElementById('unitForm').reset();
            document.getElementById('status').value = 'Tersedia';
            document.getElementById('unitModal').style.display = 'block';
        }

        function openEditModal(id, idBangunan, nomorUnit, tipe, hargaSewa, status) {
            document.getElementById('modalTitle').textContent = 'Edit Data Unit';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formIdUnit').value = id;
            document.getElementById('id_bangunan').value = idBangunan;
            document.getElementById('nomor_unit').value = nomorUnit;
            document.getElementById('tipe').value = tipe;
            document.getElementById('harga_sewa').value = hargaSewa;
            document.getElementById('status').value = status;
            document.getElementById('unitModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('unitModal').style.display = 'none';
        }

        function confirmDelete(id, name) {
            document.getElementById('deleteIdUnit').value = id;

            document.getElementById('deleteUnitName').textContent = name;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function executeDelete() {
            document.getElementById('deleteForm').submit();
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const unitModal = document.getElementById('unitModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target === unitModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        document.getElementById('unitForm').addEventListener('submit', function (e) {
            const idBangunan = document.getElementById('id_bangunan').value;
            const nomorUnit = document.getElementById('nomor_unit').value.trim();
            const tipe = document.getElementById('tipe').value.trim();
            const hargaSewa = document.getElementById('harga_sewa').value.trim();
            const status = document.getElementById('status').value;

            if (!idBangunan) {
                alert('Silakan pilih bangunan!');
                e.preventDefault();
                return false;
            }

            if (!nomorUnit) {
                alert('Nomor unit tidak boleh kosong!');
                e.preventDefault();
                return false;
            }

            if (!tipe) {
                alert('Tipe unit tidak boleh kosong!');
                e.preventDefault();
                return false;
            }

            if (!hargaSewa || isNaN(hargaSewa) || parseFloat(hargaSewa) <= 0) {
                alert('Harga sewa harus berupa angka positif!');
                e.preventDefault();
                return false;
            }

            if (!status) {
                alert('Status unit tidak boleh kosong!');
                e.preventDefault();
                return false;
            }

            return true;
        });

        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function (alert) {
                setTimeout(function () {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function () {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            const filterForm = document.getElementById('filterForm');
            const searchInput = filterForm.querySelector('input[name="search"]');
            const filterStatusSelect = filterForm.querySelector('select[name="filter_status"]');
            const filterBangunanSelect = filterForm.querySelector('select[name="filter_bangunan"]');

            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    filterForm.submit();
                }
            });

            filterStatusSelect.addEventListener('change', function () {
                filterForm.submit();
            });

            filterBangunanSelect.addEventListener('change', function () {
                filterForm.submit();
            });
        });

        function resetFilters() {
            document.getElementById('filter_status').value = '';
            document.getElementById('filter_bangunan').value = '';
            document.querySelector('input[name="search"]').value = '';
            document.getElementById('filterForm').submit();
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