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
                $alamat = trim($_POST['alamat']);

                if (empty($nama) || empty($telepon) || empty($email) || empty($alamat)) {
                    $error_message = "Nama, Telepon, Email, dan Alamat wajib diisi!";
                } else {
                    $id_pemilik = generateUUID();
                    $stmt = $conn->prepare("INSERT INTO pemilik (id_pemilik, nama, telepon, email, alamat) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("sssss", $id_pemilik, $nama, $telepon, $email, $alamat);

                        if ($stmt->execute()) {
                            $success_message = "Pemilik berhasil ditambahkan!";
                        } else {
                            if ($conn->errno == 1062) {
                                $error_message = "Email sudah terdaftar!";
                            } else {
                                $error_message = "Terjadi kesalahan saat menambahkan data: " . $stmt->error;
                            }
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Terjadi kesalahan dalam menyiapkan query: " . mysqli_error($conn);
                    }
                }
                break;

            case 'edit':
                $id_pemilik = $_POST['id_pemilik'];
                $nama = trim($_POST['nama']);
                $telepon = trim($_POST['telepon']);
                $email = trim($_POST['email']);
                $alamat = trim($_POST['alamat']);

                if (!empty($nama) && !empty($telepon) && !empty($email) && !empty($alamat)) {
                    $stmt = $conn->prepare("UPDATE pemilik SET nama = ?, telepon = ?, email = ?, alamat = ? WHERE id_pemilik = ?");
                    if ($stmt) {
                        $stmt->bind_param("sssss", $nama, $telepon, $email, $alamat, $id_pemilik);

                        if ($stmt->execute()) {
                            $success_message = "Data pemilik berhasil diupdate!";
                        } else {
                            if ($conn->errno == 1062) {
                                $error_message = "Email sudah terdaftar!";
                            } else {
                                $error_message = "Terjadi kesalahan saat mengupdate data: " . $stmt->error;
                            }
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Terjadi kesalahan dalam menyiapkan query: " . mysqli_error($conn);
                    }
                } else {
                    $error_message = "Nama, Telepon, Email, dan Alamat wajib diisi!";
                }
                break;

            case 'delete':
                $id_pemilik = $_POST['id_pemilik'];
                $stmt = $conn->prepare("DELETE FROM pemilik WHERE id_pemilik = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_pemilik);

                    if ($stmt->execute()) {
                        $success_message = "Pemilik berhasil dihapus!";
                    } else {
                        $error_message = "Terjadi kesalahan saat menghapus data: " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error_message = "Terjadi kesalahan dalam menyiapkan query: " . mysqli_error($conn);
                }
                break;
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "
    SELECT p.*, COUNT(b.id_bangunan) as jumlah_bangunan
    FROM pemilik p
    LEFT JOIN bangunan b ON p.id_pemilik = b.id_pemilik
";

$pemilik_list = [];
$stmt = null;
if (!empty($search)) {
    $query .= " WHERE p.nama LIKE ? OR p.email LIKE ? OR p.telepon LIKE ? OR p.alamat LIKE ?";
    $searchTerm = "%$search%";
    $stmt = $conn->prepare($query . " GROUP BY p.id_pemilik ORDER BY p.nama");
    if ($stmt) {
        $stmt->bind_param("ssss", $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        if (!$stmt->execute()) {
            $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data pemilik: " . $stmt->error;
        } else {
            $result = $stmt->get_result();
            $pemilik_list = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data pemilik: " . mysqli_error($conn);
    }
} else {
    $stmt = $conn->prepare($query . " GROUP BY p.id_pemilik ORDER BY p.nama");
    if ($stmt) {
        if (!$stmt->execute()) {
            $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data pemilik: " . $stmt->error;
        } else {
            $result = $stmt->get_result();
            $pemilik_list = $result->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data pemilik: " . mysqli_error($conn);
    }
}

$stats_query = "
    SELECT
        COUNT(DISTINCT p.id_pemilik) as total_pemilik,
        COUNT(DISTINCT b.id_bangunan) as total_bangunan,
        COUNT(DISTINCT u.id_unit) as total_unit
    FROM pemilik p
    LEFT JOIN bangunan b ON p.id_pemilik = b.id_pemilik
    LEFT JOIN unit u ON b.id_bangunan = u.id_bangunan
";

$stats_stmt = $conn->prepare($stats_query);
$stats = ['total_pemilik' => 0, 'total_bangunan' => 0, 'total_unit' => 0];

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
    <title>Manajemen Pemilik - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-user-tie"></i> Manajemen Pemilik - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Pemilik
                </div>
                <h1>Manajemen Pemilik</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Pemilik Baru
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
                <div class="number"><?php echo number_format($stats['total_pemilik']); ?></div>
                <div class="label">Total Pemilik</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_bangunan']); ?></div>
                <div class="label">Total Bangunan</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_unit']); ?></div>
                <div class="label">Total Unit</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" class="search-form">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari nama, email, telepon, atau alamat pemilik..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search)): ?>
                    Menampilkan <?php echo count($pemilik_list); ?> hasil untuk "<?php echo htmlspecialchars($search); ?>"
                <?php else: ?>
                    Total <?php echo count($pemilik_list); ?> pemilik terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Pemilik Terdaftar</span>
            </div>
            <?php if (empty($pemilik_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-user-tie"></i>
                    <?php if (!empty($search)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada pemilik yang cocok dengan pencarian "<?php echo htmlspecialchars($search); ?>". Coba
                            gunakan kata kunci yang berbeda.</p>
                    <?php else: ?>
                        <h3>Belum ada data pemilik</h3>
                        <p>Sistem belum memiliki data pemilik yang terdaftar. Klik tombol "Tambah Pemilik Baru" untuk
                            menambahkan data pemilik pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-user"></i> Nama Pemilik</th>
                                <th><i class="fas fa-phone"></i> Telepon</th>
                                <th><i class="fas fa-envelope"></i> Email</th>
                                <th><i class="fas fa-map-marker-alt"></i> Alamat</th>
                                <th><i class="fas fa-building"></i> Jumlah Bangunan</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pemilik_list as $pemilik): ?>
                                <tr>
                                    <td class="data-cell primary">
                                        <strong><?php echo htmlspecialchars($pemilik['nama']); ?></strong>
                                    </td>
                                    <td class="data-cell contact">
                                        <?php echo htmlspecialchars($pemilik['telepon']); ?>
                                    </td>
                                    <td class="data-cell contact">
                                        <?php echo htmlspecialchars($pemilik['email']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($pemilik['alamat']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <span
                                            class="building-count <?php echo $pemilik['jumlah_bangunan'] == 0 ? 'zero' : ''; ?>">
                                            <?php echo $pemilik['jumlah_bangunan']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit"
                                                onclick="openEditModal('<?php echo htmlspecialchars($pemilik['id_pemilik']); ?>', '<?php echo htmlspecialchars($pemilik['nama'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pemilik['telepon'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pemilik['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($pemilik['alamat'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo htmlspecialchars($pemilik['id_pemilik']); ?>', '<?php echo htmlspecialchars($pemilik['nama'], ENT_QUOTES); ?>')">
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
                <h3><i class="fas fa-user-plus"></i> Tambah Pemilik Baru</h3>
                <button class="close-btn" onclick="closeModal('addModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label for="add_nama"><i class="fas fa-user"></i> Nama Pemilik *</label>
                        <input type="text" id="add_nama" name="nama" required
                            placeholder="Masukkan nama lengkap pemilik">
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
                        <label for="add_alamat"><i class="fas fa-map-marker-alt"></i> Alamat *</label> <textarea
                            id="add_alamat" name="alamat" required
                            placeholder="Masukkan alamat lengkap pemilik..."></textarea>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Tambah Pemilik
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit Data Pemilik</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="edit_id" name="id_pemilik">
                    <div class="form-group">
                        <label for="edit_nama"><i class="fas fa-user"></i> Nama Pemilik *</label>
                        <input type="text" id="edit_nama" name="nama" required
                            placeholder="Masukkan nama lengkap pemilik">
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
                        <label for="edit_alamat"><i class="fas fa-map-marker-alt"></i> Alamat *</label> <textarea
                            id="edit_alamat" name="alamat" required
                            placeholder="Masukkan alamat lengkap (opsional)"></textarea>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Data Pemilik
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
                        pemilik:</p>
                    <p id="deleteOwnerName"
                        style="font-weight: 600; color: #C08B8B; font-size: 1.2em; margin-bottom: 20px;"></p>
                    <div
                        style="background-color: #fff3cd; border: 1px solid #ffeaa7; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                        <p style="color: #856404; font-size: 0.9em; margin: 0;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 5px;"></i>
                            <strong>Perhatian:</strong> Semua bangunan yang dimiliki akan menjadi tidak memiliki
                            pemilik.
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
        <input type="hidden" id="delete_id" name="id_pemilik">
    </form>

    <script>
        let deleteOwnerId = '';
        let deleteOwnerName = '';

        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
            document.getElementById('add_nama').value = '';
            document.getElementById('add_telepon').value = '';
            document.getElementById('add_email').value = '';
            document.getElementById('add_alamat').value = '';
        }

        function openEditModal(id, nama, telepon, email, alamat) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_telepon').value = telepon;
            document.getElementById('edit_email').value = email;
            document.getElementById('edit_alamat').value = alamat;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function confirmDelete(id, nama) {
            deleteOwnerId = id;
            deleteOwnerName = nama;
            document.getElementById('deleteOwnerName').textContent = nama;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function executeDelete() {
            document.getElementById('delete_id').value = deleteOwnerId;
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
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function () {
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