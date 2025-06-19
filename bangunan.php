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
                $id_pemilik = trim($_POST['id_pemilik']);
                $nama_bangunan = trim($_POST['nama_bangunan']);
                $alamat = trim($_POST['alamat']);

                if (empty($id_pemilik) || empty($nama_bangunan) || empty($alamat)) {
                    $error_message = "Semua field wajib diisi!";
                } else {
                    $id_bangunan = generateUUID();
                    $stmt = $conn->prepare("INSERT INTO bangunan (id_bangunan, id_pemilik, nama_bangunan, alamat) VALUES (?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("ssss", $id_bangunan, $id_pemilik, $nama_bangunan, $alamat);
                        if ($stmt->execute()) {
                            $success_message = "Bangunan berhasil ditambahkan!";
                        } else {
                            $error_message = "Gagal menambahkan bangunan: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement: " . mysqli_error($conn);
                    }
                }
                break;

            case 'edit':
                $id_bangunan = $_POST['id_bangunan'];
                $id_pemilik = trim($_POST['id_pemilik']);
                $nama_bangunan = trim($_POST['nama_bangunan']);
                $alamat = trim($_POST['alamat']);

                if (empty($id_pemilik) || empty($nama_bangunan) || empty($alamat)) {
                    $error_message = "Semua field wajib diisi!";
                } else {
                    $stmt = $conn->prepare("UPDATE bangunan SET id_pemilik = ?, nama_bangunan = ?, alamat = ? WHERE id_bangunan = ?");
                    if ($stmt) {
                        $stmt->bind_param("ssss", $id_pemilik, $nama_bangunan, $alamat, $id_bangunan);
                        if ($stmt->execute()) {
                            $success_message = "Data bangunan berhasil diupdate!";
                        } else {
                            $error_message = "Gagal mengupdate bangunan: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $error_message = "Gagal menyiapkan statement: " . mysqli_error($conn);
                    }
                }
                break;

            case 'delete':
                $id_bangunan = $_POST['id_bangunan'];
                $stmt = $conn->prepare("DELETE FROM bangunan WHERE id_bangunan = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $id_bangunan);
                    if ($stmt->execute()) {
                        $success_message = "Bangunan berhasil dihapus!";
                    } else {
                        $error_message = "Gagal menghapus bangunan: " . $stmt->error;
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
    SELECT b.*, p.nama as nama_pemilik, COUNT(u.id_unit) as jumlah_unit
    FROM bangunan b
    LEFT JOIN pemilik p ON b.id_pemilik = p.id_pemilik
    LEFT JOIN unit u ON b.id_bangunan = u.id_bangunan
";

$params = [];
$types = '';

if (!empty($search)) {
    $query .= " WHERE b.nama_bangunan LIKE ? OR b.alamat LIKE ? OR p.nama LIKE ? OR (p.nama IS NULL AND ? LIKE '%tidak ditemukan%')";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types = "ssss";
}

$query .= " GROUP BY b.id_bangunan ORDER BY b.nama_bangunan";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $bangunan_list = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $bangunan_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data bangunan: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data bangunan: " . mysqli_error($conn);
}


$pemilik_stmt = $conn->prepare("SELECT id_pemilik, nama FROM pemilik ORDER BY nama");
$pemilik_options = [];
if ($pemilik_stmt) {
    if ($pemilik_stmt->execute()) {
        $pemilik_result = $pemilik_stmt->get_result();
        $pemilik_options = $pemilik_result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil data pemilik: " . $pemilik_stmt->error;
    }
    $pemilik_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query data pemilik: " . mysqli_error($conn);
}


$stats_query = "
    SELECT
        COUNT(DISTINCT b.id_bangunan) as total_bangunan,
        COUNT(DISTINCT u.id_unit) as total_unit,
        COUNT(DISTINCT p.id_pemilik) as total_pemilik
    FROM bangunan b
    LEFT JOIN unit u ON b.id_bangunan = u.id_bangunan
    LEFT JOIN pemilik p ON b.id_pemilik = p.id_pemilik
";

$stats_stmt = $conn->prepare($stats_query);
$stats = ['total_bangunan' => 0, 'total_unit' => 0, 'total_pemilik' => 0];

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
    <title>Manajemen Bangunan - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-building"></i> Manajemen Bangunan - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a> > Manajemen Bangunan
                </div>
                <h1>Manajemen Bangunan</h1>
            </div>
            <button class="add-button" onclick="openAddModal()">
                <i class="fas fa-plus-circle"></i> Tambah Bangunan Baru
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
                <div class="number"><?php echo number_format($stats['total_bangunan']); ?></div>
                <div class="label">Total Bangunan</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_unit']); ?></div>
                <div class="label">Total Unit</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($stats['total_pemilik']); ?></div>
                <div class="label">Total Pemilik</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" class="search-form">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari nama bangunan, alamat, atau nama pemilik..."
                        value="<?php echo htmlspecialchars($search); ?>">
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search)): ?>
                    Menampilkan <?php echo count($bangunan_list); ?> hasil untuk "<?php echo htmlspecialchars($search); ?>"
                <?php else: ?>
                    Total <?php echo count($bangunan_list); ?> bangunan terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Bangunan Terdaftar</span>
            </div>
            <?php if (empty($bangunan_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-building"></i>
                    <?php if (!empty($search)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada bangunan yang cocok dengan pencarian "<?php echo htmlspecialchars($search); ?>". Coba
                            gunakan kata kunci yang berbeda.</p>
                    <?php else: ?>
                        <h3>Belum ada data bangunan</h3>
                        <p>Sistem belum memiliki data bangunan yang terdaftar. Klik tombol "Tambah Bangunan Baru" untuk
                            menambahkan data bangunan pertama.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-building"></i> Nama Bangunan</th>
                                <th><i class="fas fa-map-marker-alt"></i> Alamat</th>
                                <th><i class="fas fa-user-tie"></i> Pemilik</th>
                                <th><i class="fas fa-door-open"></i> Jumlah Unit</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bangunan_list as $bangunan): ?>
                                <tr>
                                    <td class="data-cell primary">
                                        <strong><?php echo htmlspecialchars($bangunan['nama_bangunan']); ?></strong>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($bangunan['alamat']); ?>
                                    </td>
                                    <td class="data-cell owner">
                                        <?php echo htmlspecialchars($bangunan['nama_pemilik'] ?? 'Tidak ditemukan'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <span class="unit-count <?php echo $bangunan['jumlah_unit'] == 0 ? 'zero' : ''; ?>">
                                            <?php echo $bangunan['jumlah_unit']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-edit"
                                                onclick="openEditModal('<?php echo $bangunan['id_bangunan']; ?>', '<?php echo htmlspecialchars($bangunan['id_pemilik'] ?? ''); ?>', '<?php echo htmlspecialchars($bangunan['nama_bangunan'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bangunan['alamat'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn-delete"
                                                onclick="confirmDelete('<?php echo $bangunan['id_bangunan']; ?>', '<?php echo htmlspecialchars($bangunan['nama_bangunan'], ENT_QUOTES); ?>')">
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

    <div id="bangunanModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Tambah Bangunan Baru</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="bangunanForm" method="POST">
                    <input type="hidden" id="formAction" name="action" value="add">
                    <input type="hidden" id="formIdBangunan" name="id_bangunan" value="">

                    <div class="form-group">
                        <label for="id_pemilik">Pemilik Bangunan</label>
                        <select id="id_pemilik" name="id_pemilik" required>
                            <option value="">Pilih Pemilik</option>
                            <?php foreach ($pemilik_options as $pemilik): ?>
                                <option value="<?php echo $pemilik['id_pemilik']; ?>">
                                    <?php echo htmlspecialchars($pemilik['nama']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="nama_bangunan">Nama Bangunan</label>
                        <input type="text" id="nama_bangunan" name="nama_bangunan" required
                            placeholder="Masukkan nama bangunan atau gedung">
                    </div>

                    <div class="form-group">
                        <label for="alamat">Alamat Lengkap</label>
                        <textarea id="alamat" name="alamat" required
                            placeholder="Masukkan alamat lengkap bangunan..."></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Simpan Data Bangunan
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #C08B8B, #a67373);">
                <h3>Konfirmasi Penghapusan</h3>
                <button class="close-btn" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 20px 0;">
                    <i class="fas fa-exclamation-triangle"
                        style="font-size: 3em; color: #C08B8B; margin-bottom: 20px;"></i>
                    <h4 style="color: #332D56; margin-bottom: 15px;">Apakah Anda yakin?</h4>
                    <p style="color: #666; margin-bottom: 25px; line-height: 1.6;">
                        Bangunan "<strong id="deleteBangunanName"></strong>" akan dihapus secara permanen dari sistem.
                        <br><strong>Tindakan ini tidak dapat dibatalkan!</strong>
                        <br>
                        <small style="color: #dc3545; font-weight: bold;">Semua unit dan data terkait lainnya di dalam
                            bangunan ini juga akan dihapus.</small>
                    </p>
                </div>

                <form id="deleteForm" method="POST" style="display: flex; gap: 15px;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" id="deleteIdBangunan" name="id_bangunan" value="">

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
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Tambah Bangunan Baru';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formIdBangunan').value = '';
            document.getElementById('bangunanForm').reset();
            document.getElementById('bangunanModal').style.display = 'block';
        }

        function openEditModal(id, idPemilik, namaBangunan, alamat) {
            document.getElementById('modalTitle').textContent = 'Edit Data Bangunan';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formIdBangunan').value = id;
            document.getElementById('id_pemilik').value = idPemilik;
            document.getElementById('nama_bangunan').value = namaBangunan;
            document.getElementById('alamat').value = alamat;
            document.getElementById('bangunanModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('bangunanModal').style.display = 'none';
        }

        function confirmDelete(id, nama) {
            document.getElementById('deleteIdBangunan').value = id;
            document.getElementById('deleteBangunanName').textContent = nama;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const bangunanModal = document.getElementById('bangunanModal');
            const deleteModal = document.getElementById('deleteModal');

            if (event.target === bangunanModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        document.getElementById('bangunanForm').addEventListener('submit', function (e) {
            const idPemilik = document.getElementById('id_pemilik').value;
            const namaBangunan = document.getElementById('nama_bangunan').value.trim();
            const alamat = document.getElementById('alamat').value.trim();

            if (!idPemilik) {
                alert('Silakan pilih pemilik bangunan!');
                e.preventDefault();
                return false;
            }

            if (!namaBangunan) {
                alert('Nama bangunan tidak boleh kosong!');
                e.preventDefault();
                return false;
            }

            if (!alamat) {
                alert('Alamat bangunan tidak boleh kosong!');
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

        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    this.form.submit();
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

        function printTable() {
            const printWindow = window.open('', '_blank');
            const tableContent = document.querySelector('.data-table-container').outerHTML;

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Data Bangunan - SiKontra</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; }
                        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
                        th { background-color: #f5f5f5; font-weight: bold; }
                        .action-buttons { display: none; }
                        @media print {
                            body { margin: 0; }
                            .table-header-section { background: #333 !important; color: white !important; }
                        }
                    </style>
                </head>
                <body>
                    <h2>Data Bangunan - SiKontra</h2>
                    <p>Dicetak pada: ${new Date().toLocaleDateString('id-ID')}</p>
                    ${tableContent}
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 500);
        }
    </script>
</body>

</html>