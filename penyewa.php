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
                $nama = trim($_POST['nama']);
                $telepon = trim($_POST['telepon']);
                $email = trim($_POST['email']);
                $pekerjaan = trim($_POST['pekerjaan']);
                $alamat_asal = trim($_POST['alamat_asal']);

                if (empty($nama) || empty($telepon) || empty($email) || empty($pekerjaan) || empty($alamat_asal)) {
                    $error_message = "Nama, Telepon, Email, Pekerjaan, dan Alamat Asal wajib diisi!";
                } else {
                    $id_penyewa = generateUUID();
                    $stmt = $conn->prepare("INSERT INTO penyewa (id_penyewa, nama, telepon, email, pekerjaan, alamat_asal) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $id_penyewa, $nama, $telepon, $email, $pekerjaan, $alamat_asal);

                        if ($stmt->execute()) {
                            $success_message = "Penyewa berhasil ditambahkan!";
                        } else {
                            if (mysqli_errno($conn) == 1062) {
                                $error_message = "Email sudah terdaftar!";
                            } else {
                                $error_message = "Gagal menambahkan penyewa: " . $stmt->error;
                            }
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement: " . mysqli_error($conn);
                    }
                }
                break;

            case 'edit':
                $id_penyewa = $_POST['id_penyewa'];
                $nama = trim($_POST['nama']);
                $telepon = trim($_POST['telepon']);
                $email = trim($_POST['email']);
                $pekerjaan = trim($_POST['pekerjaan']);
                $alamat_asal = trim($_POST['alamat_asal']);

                if (empty($nama) || empty($telepon) || empty($email) || empty($pekerjaan) || empty($alamat_asal)) {
                    $error_message = "Nama, Telepon, Email, Pekerjaan, dan Alamat Asal wajib diisi!";
                } else {
                    $stmt = $conn->prepare("UPDATE penyewa SET nama = ?, telepon = ?, email = ?, pekerjaan = ?, alamat_asal = ? WHERE id_penyewa = ?");
                    if ($stmt) {
                        $stmt->bind_param("ssssss", $nama, $telepon, $email, $pekerjaan, $alamat_asal, $id_penyewa);

                        if ($stmt->execute()) {
                            $success_message = "Data penyewa berhasil diupdate!";
                        } else {
                            if (mysqli_errno($conn) == 1062) {
                                $error_message = "Email sudah terdaftar!";
                            } else {
                                $error_message = "Gagal mengupdate penyewa: " . $stmt->error;
                            }
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement: " . mysqli_error($conn);
                    }
                }
                break;

            case 'delete':
                $id_penyewa = $_POST['id_penyewa'];
                $stmt = $conn->prepare("DELETE FROM penyewa WHERE id_penyewa = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_penyewa);

                    if ($stmt->execute()) {
                        $success_message = "Penyewa berhasil dihapus!";
                    } else {
                        $error_message = "Gagal menghapus penyewa: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Gagal menyiapkan statement: " . mysqli_error($conn);
                }
                break;
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "
    SELECT p.*, COUNT(s.id_sewa) as jumlah_sewa_aktif
    FROM penyewa p
    LEFT JOIN sewa s ON p.id_penyewa = s.id_penyewa AND s.status = 'Aktif' AND s.tanggal_selesai >= CURDATE()
";

$penyewa_list = [];
$stmt = null;
if (!empty($search)) {
    $query .= " WHERE p.nama LIKE ? OR p.telepon LIKE ? OR p.email LIKE ? OR p.pekerjaan LIKE ? OR p.alamat_asal LIKE ?";
    $searchTerm = "%$search%";
    $stmt = $conn->prepare($query . " GROUP BY p.id_penyewa ORDER BY p.nama");
    if ($stmt) {
        $stmt->bind_param("sssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data penyewa: " . mysqli_error($conn);
    }
} else {
    $stmt = $conn->prepare($query . " GROUP BY p.id_penyewa ORDER BY p.nama");
    if (!$stmt) {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data penyewa: " . mysqli_error($conn);
    }
}

if ($stmt) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $penyewa_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data: " . $stmt->error;
    }
    $stmt->close();
}

// --- PERBAIKAN DI SINI: MENAMBAHKAN JUMLAH BANGUNAN DAN UNIT KE STATISTIK ---
$stats_query = "
    SELECT
        COUNT(DISTINCT p.id_penyewa) as total_penyewa,
        SUM(CASE WHEN s.status = 'Aktif' AND s.tanggal_selesai >= CURDATE() THEN 1 ELSE 0 END) as total_sewa_aktif,
        COUNT(DISTINCT b.id_bangunan) as total_bangunan,
        COUNT(DISTINCT u.id_unit) as total_unit
    FROM penyewa p
    LEFT JOIN sewa s ON p.id_penyewa = s.id_penyewa
    LEFT JOIN unit u ON s.id_unit = u.id_unit
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
";

$stats_stmt = $conn->prepare($stats_query);
$stats = ['total_penyewa' => 0, 'total_sewa_aktif' => 0, 'total_bangunan' => 0, 'total_unit' => 0];

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
// --- AKHIR PERBAIKAN BAGIAN STATISTIK ---
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Penyewa - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-user-tie"></i> Manajemen Penyewa - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Penyewa
                </div>
                <h1>Manajemen Penyewa</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Penyewa Baru
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
                <div class="number"><?php echo number_format($stats['total_penyewa']); ?></div>
                <div class="label">Total Penyewa</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_sewa_aktif']); ?></div>
                <div class="label">Sewa Aktif</div>
            </div>
            <div class="stat-card">
                <div class="number">
                    <?php
                    // --- PASTIKAN MENGGUNAKAN KUNCI YANG BENAR DARI ARRAY $stats ---
                    echo number_format($stats['total_bangunan']);
                    ?>
                </div>
                <div class="label">Total Bangunan</div>
            </div>
            <div class="stat-card">
                <div class="number">
                    <?php
                    // --- PASTIKAN MENGGUNAKAN KUNCI YANG BENAR DARI ARRAY $stats ---
                    echo number_format($stats['total_unit']);
                    ?>
                </div>
                <div class="label">Total Unit</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" class="search-form">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search"
                        placeholder="Cari nama, email, telepon, pekerjaan, atau alamat asal penyewa..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search)): ?>
                    Menampilkan <?php echo count($penyewa_list); ?> hasil untuk "<?php echo htmlspecialchars($search); ?>"
                <?php else: ?>
                    Total <?php echo count($penyewa_list); ?> penyewa terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Penyewa Terdaftar</span>
            </div>
            <?php if (empty($penyewa_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-friends"></i>
                    <?php if (!empty($search)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada penyewa yang cocok dengan pencarian "<?php echo htmlspecialchars($search); ?>". Coba
                            gunakan kata kunci yang berbeda.</p>
                    <?php else: ?>
                        <h3>Belum ada data penyewa</h3>
                        <p>Sistem belum memiliki data penyewa yang terdaftar. Klik tombol "Tambah Penyewa Baru" untuk
                            menambahkan data penyewa pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-user"></i> Nama Penyewa</th>
                                <th><i class="fas fa-phone"></i> Telepon</th>
                                <th><i class="fas fa-envelope"></i> Email</th>
                                <th><i class="fas fa-briefcase"></i> Pekerjaan</th>
                                <th><i class="fas fa-map-pin"></i> Alamat Asal</th>
                                <th><i class="fas fa-handshake"></i> Sewa Aktif</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($penyewa_list as $penyewa): ?>
                                <tr>
                                    <td class="data-cell primary">
                                        <strong><?php echo htmlspecialchars($penyewa['nama']); ?></strong>
                                    </td>
                                    <td class="data-cell contact">
                                        <?php echo htmlspecialchars($penyewa['telepon']); ?>
                                    </td>
                                    <td class="data-cell contact">
                                        <?php echo htmlspecialchars($penyewa['email']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($penyewa['pekerjaan']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($penyewa['alamat_asal']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <span
                                            class="building-count <?php echo $penyewa['jumlah_sewa_aktif'] == 0 ? 'zero' : ''; ?>">
                                            <?php echo $penyewa['jumlah_sewa_aktif']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit"
                                                onclick="openEditModal('<?php echo htmlspecialchars($penyewa['id_penyewa']); ?>', '<?php echo htmlspecialchars($penyewa['nama'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($penyewa['telepon'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($penyewa['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($penyewa['pekerjaan'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($penyewa['alamat_asal'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo htmlspecialchars($penyewa['id_penyewa']); ?>', '<?php echo htmlspecialchars($penyewa['nama'], ENT_QUOTES); ?>')">
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

    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Tambah Penyewa Baru</h3>
                <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="add_nama"><i class="fas fa-user"></i> Nama Penyewa *</label>
                        <input type="text" id="add_nama" name="nama" required
                            placeholder="Masukkan nama lengkap penyewa">
                    </div>
                    <div class="form-group">
                        <label for="add_telepon"><i class="fas fa-phone"></i> Telepon *</label>
                        <input type="text" id="add_telepon" name="telepon" required placeholder="Contoh: 081234567890">
                    </div>
                    <div class="form-group">
                        <label for="add_email"><i class="fas fa-envelope"></i> Email *</label>
                        <input type="email" id="add_email" name="email" required placeholder="Contoh: nama@email.com">
                    </div>
                    <div class="form-group">
                        <label for="add_pekerjaan"><i class="fas fa-briefcase"></i> Pekerjaan *</label> <input
                            type="text" id="add_pekerjaan" name="pekerjaan" required
                            placeholder="Masukkan pekerjaan...">
                    </div>
                    <div class="form-group">
                        <label for="add_alamat_asal"><i class="fas fa-map-pin"></i> Alamat Asal *</label> <textarea
                            id="add_alamat_asal" name="alamat_asal" required
                            placeholder="Masukkan alamat asal..."></textarea>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Tambah Penyewa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit Data Penyewa</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="edit_id" name="id_penyewa">
                    <div class="form-group">
                        <label for="edit_nama"><i class="fas fa-user"></i> Nama Penyewa *</label>
                        <input type="text" id="edit_nama" name="nama" required
                            placeholder="Masukkan nama lengkap penyewa">
                    </div>
                    <div class="form-group">
                        <label for="edit_telepon"><i class="fas fa-phone"></i> Telepon *</label>
                        <input type="text" id="edit_telepon" name="telepon" required placeholder="Contoh: 081234567890">
                    </div>
                    <div class="form-group">
                        <label for="edit_email"><i class="fas fa-envelope"></i> Email *</label>
                        <input type="email" id="edit_email" name="email" required placeholder="Contoh: nama@email.com">
                    </div>
                    <div class="form-group">
                        <label for="edit_pekerjaan"><i class="fas fa-briefcase"></i> Pekerjaan *</label> <input
                            type="text" id="edit_pekerjaan" name="pekerjaan" required
                            placeholder="Masukkan pekerjaan (opsional)">
                    </div>
                    <div class="form-group">
                        <label for="edit_alamat_asal"><i class="fas fa-map-pin"></i> Alamat Asal *</label> <textarea
                            id="edit_alamat_asal" name="alamat_asal" required
                            placeholder="Masukkan alamat asal (opsional)"></textarea>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Data Penyewa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #C08B8B, #a67373);">
                <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Hapus</h3>
                <button class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-trash-alt" style="font-size: 3em; color: #C08B8B; margin-bottom: 20px;"></i>
                    <p style="font-size: 1.1em; margin-bottom: 10px; color: #333;">Apakah Anda yakin ingin menghapus
                        penyewa:</p>
                    <p id="deletePenyewaName"
                        style="font-weight: 600; color: #C08B8B; font-size: 1.2em; margin-bottom: 20px;"></p>
                    <div
                        style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #856404; font-size: 0.9em; margin: 0;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 5px;"></i>
                            <strong>Perhatian:</strong> Semua kontrak sewa yang terkait akan kehilangan referensi
                            penyewa.
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
        <input type="hidden" id="delete_id" name="id_penyewa">
    </form>

    <script>
        let deletePenyewaId = '';
        let deletePenyewaName = '';

        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
            document.getElementById('add_nama').value = '';
            document.getElementById('add_telepon').value = '';
            document.getElementById('add_email').value = '';
            document.getElementById('add_pekerjaan').value = '';
            document.getElementById('add_alamat_asal').value = '';
        }

        function openEditModal(id, nama, telepon, email, pekerjaan, alamat_asal) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_telepon').value = telepon;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_pekerjaan').value = pekerjaan;
            document.getElementById('edit_alamat_asal').value = alamat_asal;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function confirmDelete(id, nama) {
            deletePenyewaId = id;
            deletePenyewaName = nama;
            document.getElementById('deletePenyewaName').textContent = nama;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function executeDelete() {
            document.getElementById('delete_id').value = deletePenyewaId;
            document.getElementById('deleteForm').submit();
        }

        window.onclick = function (event) {
            const modals = ['addModal', 'editModal', 'deleteModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const phoneInputs = document.querySelectorAll('input[name="telepon"]');
            phoneInputs.forEach(input => {
                input.addEventListener('input', function () {
                    this.value = this.value.replace(/[^0-9+\-]/g, '');
                });
            });

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
                closeModal('addModal');
                closeModal('editModal');
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