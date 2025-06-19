<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$success_message = '';
$error_message = '';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_action = isset($_GET['filter_action']) ? trim($_GET['filter_action']) : '';
$filter_user = isset($_GET['filter_user']) ? trim($_GET['filter_user']) : '';
$filter_transaksi_id = isset($_GET['id_transaksi']) ? trim($_GET['id_transaksi']) : '';
$origin = isset($_GET['origin']) ? trim($_GET['origin']) : '';

$query = "
    SELECT
        lt.*,
        t.tanggal_pembayaran,
        t.jumlah as transaksi_jumlah,
        t.metode_pembayaran,
        t.status as transaksi_status,
        s.tanggal_mulai,
        s.tanggal_selesai,
        u.nomor_unit,
        b.nama_bangunan,
        p.nama as nama_penyewa
    FROM log_transaksi lt
    LEFT JOIN transaksi t ON lt.id_transaksi = t.id_transaksi
    LEFT JOIN sewa s ON t.id_sewa = s.id_sewa
    LEFT JOIN unit u ON s.id_unit = u.id_unit
    LEFT JOIN bangunan b ON u.id_bangunan = b.id_bangunan
    LEFT JOIN penyewa p ON s.id_penyewa = p.id_penyewa
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (lt.aksi LIKE ? OR lt.user_log LIKE ? OR p.nama LIKE ? OR b.nama_bangunan LIKE ? OR u.nomor_unit LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $types .= "sssss";
}

if (!empty($filter_action)) {
    $query .= " AND lt.aksi = ?";
    $params[] = $filter_action;
    $types .= "s";
}

if (!empty($filter_user)) {
    $query .= " AND lt.user_log = ?";
    $params[] = $filter_user;
    $types .= "s";
}

if (!empty($filter_transaksi_id)) {
    $query .= " AND lt.id_transaksi = ?";
    $params[] = $filter_transaksi_id;
    $types .= "s";
}

$query .= " ORDER BY lt.tanggal_log DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $log_list = [];
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $log_list = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        $error_message = "Gagal mengambil data log transaksi: " . $stmt->error;
    }
    $stmt->close();
} else {
    $error_message = "Gagal menyiapkan query data log transaksi: " . mysqli_error($conn);
}

$actions_query = "SELECT DISTINCT aksi FROM log_transaksi ORDER BY aksi";
$users_query = "SELECT DISTINCT user_log FROM log_transaksi ORDER BY user_log";

$actions_options = [];
$users_options = [];

$stmt_actions = $conn->prepare($actions_query);
if ($stmt_actions) {
    if ($stmt_actions->execute()) {
        $result_actions = $stmt_actions->get_result();
        while ($row = $result_actions->fetch_assoc()) {
            $actions_options[] = $row['aksi'];
        }
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil opsi aksi: " . $stmt_actions->error;
    }
    $stmt_actions->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query opsi aksi: " . mysqli_error($conn);
}


$stmt_users = $conn->prepare($users_query);
if ($stmt_users) {
    if ($stmt_users->execute()) {
        $result_users = $stmt_users->get_result();
        while ($row = $result_users->fetch_assoc()) {
            $users_options[] = $row['user_log'];
        }
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil opsi user: " . $stmt_users->error;
    }
    $stmt_users->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query opsi user: " . mysqli_error($conn);
}


$stats_query = "SELECT COUNT(id_log) as total_logs FROM log_transaksi";
$stats = ['total_logs' => 0];

$stats_stmt = $conn->prepare($stats_query);
if ($stats_stmt) {
    if ($stats_stmt->execute()) {
        $stats_result = $stats_stmt->get_result();
        $stats = $stats_result->fetch_assoc();
    } else {
        $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal mengambil statistik log: " . $stats_stmt->error;
    }
    $stats_stmt->close();
} else {
    $error_message .= (empty($error_message) ? '' : '<br>') . "Gagal menyiapkan query statistik log: " . mysqli_error($conn);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Log Transaksi - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/log_transaksi.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-history"></i> Log Transaksi - SiKontra</h1>
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
                    <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                    <?php if ($origin === 'transaksi'): ?>
                        > <a href="transaksi.php"><i class="fas fa-money-bill-wave"></i> Transaksi</a>
                    <?php endif; ?>
                    > Log Transaksi
                </div>
                <h1>Log Transaksi</h1>
            </div>
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
                <div class="number"><?php echo number_format($stats['total_logs']); ?></div>
                <div class="label">Total Log Transaksi</div>
            </div>
        </div>

        <div class="search-filter-section">
            <div class="search-container">
                <form method="GET" class="search-form">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari aksi, user, penyewa, bangunan, atau unit..."
                        value="<?php echo htmlspecialchars($search); ?>">
                    <?php if ($origin): ?>
                        <input type="hidden" name="origin" value="<?php echo htmlspecialchars($origin); ?>">
                    <?php endif; ?>
                </form>
                <form method="GET">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <?php if ($origin): ?>
                        <input type="hidden" name="origin" value="<?php echo htmlspecialchars($origin); ?>">
                    <?php endif; ?>
                    <select name="filter_action" class="filter-select" onchange="this.form.submit()">
                        <option value="">Semua Aksi</option>
                        <?php foreach ($actions_options as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo ($filter_action == $action) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="filter_user" class="filter-select" onchange="this.form.submit()">
                        <option value="">Semua User</option>
                        <?php foreach ($users_options as $user): ?>
                            <option value="<?php echo htmlspecialchars($user); ?>" <?php echo ($filter_user == $user) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="id_transaksi" placeholder="Filter by Transaksi ID" class="filter-select"
                        onchange="this.form.submit()" value="<?php echo htmlspecialchars($filter_transaksi_id); ?>">
                </form>
            </div>
            <div class="results-info">
                <?php if (!empty($search) || !empty($filter_action) || !empty($filter_user) || !empty($filter_transaksi_id)): ?>
                    Menampilkan <?php echo count($log_list); ?> hasil
                <?php else: ?>
                    Total <?php echo count($log_list); ?> log transaksi terdaftar
                <?php endif; ?>
            </div>
        </div>

        <div class="data-table-container">
            <div class="table-header-section">
                <i class="fas fa-table"></i>
                <span>Data Log Transaksi</span>
            </div>
            <?php if (empty($log_list)): ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <?php if (!empty($search) || !empty($filter_action) || !empty($filter_user) || !empty($filter_transaksi_id)): ?>
                        <h3>Tidak ada hasil yang ditemukan</h3>
                        <p>Tidak ada log transaksi yang cocok dengan kriteria pencarian Anda.</p>
                    <?php else: ?>
                        <h3>Belum ada data log transaksi</h3>
                        <p>Sistem belum memiliki data log transaksi. Log akan dibuat secara otomatis saat ada perubahan
                            transaksi.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="fas fa-calendar-alt"></i> Tanggal Log</th>
                                <th><i class="fas fa-cogs"></i> Aksi</th>
                                <th><i class="fas fa-user"></i> User</th>
                                <th><i class="fas fa-receipt"></i> ID Transaksi</th>
                                <th><i class="fas fa-info-circle"></i> Status Lama</th>
                                <th><i class="fas fa-info-circle"></i> Status Baru</th>
                                <th><i class="fas fa-money-bill-wave"></i> Jumlah Lama</th>
                                <th><i class="fas fa-money-bill-wave"></i> Jumlah Baru</th>
                                <th><i class="fas fa-home"></i> Bangunan</th>
                                <th><i class="fas fa-door-open"></i> Unit</th>
                                <th><i class="fas fa-user-tag"></i> Penyewa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log_list as $log): ?>
                                <tr>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars(date('d F Y H:i:s', strtotime($log['tanggal_log']))); ?>
                                    </td>
                                    <td class="data-cell primary">
                                        <?php echo htmlspecialchars($log['aksi']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($log['user_log']); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($log['id_transaksi'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <span
                                            class="status-badge status-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $log['status_lama']))); ?>">
                                            <?php echo htmlspecialchars($log['status_lama'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="data-cell">
                                        <span
                                            class="status-badge status-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', $log['status_baru']))); ?>">
                                            <?php echo htmlspecialchars($log['status_baru'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="data-cell">
                                        Rp <?php echo number_format($log['jumlah_lama'] ?? 0, 0, ',', '.'); ?>
                                    </td>
                                    <td class="data-cell">
                                        Rp <?php echo number_format($log['jumlah_baru'] ?? 0, 0, ',', '.'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($log['nama_bangunan'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($log['nomor_unit'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="data-cell">
                                        <?php echo htmlspecialchars($log['nama_penyewa'] ?? 'N/A'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
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

            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.closest('form').submit();
                    }
                });
            }
        });

        document.addEventListener('keydown', function (e) {
        });
    </script>
</body>

</html>