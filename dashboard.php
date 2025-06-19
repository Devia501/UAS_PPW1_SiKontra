<?php
session_start();
require_once 'config.php';
require_once 'auth.php';

requireLogin();

$access_denied_message = '';
if (isset($_SESSION['access_denied'])) {
    $access_denied_message = $_SESSION['access_denied'];
    unset($_SESSION['access_denied']);
}

$dashboard_stats = [
    'total_bangunan' => 0,
    'total_unit' => 0,
    'total_penyewa_aktif' => 0,
    'total_transaksi_bulan_ini_amount' => 0
];
$error_message_stats = '';

$stmt_bangunan = $conn->prepare("SELECT COUNT(id_bangunan) as total_bangunan FROM bangunan");
if ($stmt_bangunan) {
    if ($stmt_bangunan->execute()) {
        $result_bangunan = $stmt_bangunan->get_result();
        $data_bangunan = $result_bangunan->fetch_assoc();
        $dashboard_stats['total_bangunan'] = $data_bangunan['total_bangunan'];
    } else {
        $error_message_stats .= "Gagal mengambil statistik bangunan: " . $stmt_bangunan->error . "<br>";
    }
    $stmt_bangunan->close();
} else {
    $error_message_stats .= "Gagal menyiapkan query statistik bangunan: " . mysqli_error($conn) . "<br>";
}

$stmt_unit = $conn->prepare("SELECT COUNT(id_unit) as total_unit FROM unit");
if ($stmt_unit) {
    if ($stmt_unit->execute()) {
        $result_unit = $stmt_unit->get_result();
        $data_unit = $result_unit->fetch_assoc();
        $dashboard_stats['total_unit'] = $data_unit['total_unit'];
    } else {
        $error_message_stats .= "Gagal mengambil statistik unit: " . $stmt_unit->error . "<br>";
    }
    $stmt_unit->close();
} else {
    $error_message_stats .= "Gagal menyiapkan query statistik unit: " . mysqli_error($conn) . "<br>";
}

$stmt_penyewa_aktif = $conn->prepare("SELECT COUNT(DISTINCT s.id_penyewa) as total_penyewa_aktif FROM sewa s WHERE s.status = 'Aktif' AND s.tanggal_selesai >= CURDATE()");
if ($stmt_penyewa_aktif) {
    if ($stmt_penyewa_aktif->execute()) {
        $result_penyewa_aktif = $stmt_penyewa_aktif->get_result();
        $data_penyewa_aktif = $result_penyewa_aktif->fetch_assoc();
        $dashboard_stats['total_penyewa_aktif'] = $data_penyewa_aktif['total_penyewa_aktif'];
    } else {
        $error_message_stats .= "Gagal mengambil statistik penyewa aktif: " . $stmt_penyewa_aktif->error . "<br>";
    }
    $stmt_penyewa_aktif->close();
} else {
    $error_message_stats .= "Gagal menyiapkan query statistik penyewa aktif: " . mysqli_error($conn) . "<br>";
}

$current_month = date('Y-m');
$stmt_transaksi = $conn->prepare("SELECT SUM(jumlah) as total_transaksi_bulan_ini_amount FROM transaksi WHERE DATE_FORMAT(tanggal_pembayaran, '%Y-%m') = ? AND status = 'Lunas'");
if ($stmt_transaksi) {
    $stmt_transaksi->bind_param("s", $current_month);
    if ($stmt_transaksi->execute()) {
        $result_transaksi = $stmt_transaksi->get_result();
        $data_transaksi = $result_transaksi->fetch_assoc();
        $dashboard_stats['total_transaksi_bulan_ini_amount'] = $data_transaksi['total_transaksi_bulan_ini_amount'] ?? 0;
    } else {
        $error_message_stats .= "Gagal mengambil statistik transaksi bulan ini: " . $stmt_transaksi->error . "<br>";
    }
    $stmt_transaksi->close();
} else {
    $error_message_stats .= "Gagal menyiapkan query statistik transaksi bulan ini: " . mysqli_error($conn) . "<br>";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style_dashboard.css">
</head>

<body>
    <div class="header">
        <h1><i class="fas fa-home"></i> Dashboard SiKontra</h1>
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

    <div class="container">
        <?php if (!empty($access_denied_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $access_denied_message; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error_message_stats)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <span>Terjadi kesalahan saat mengambil data statistik: <br><?php echo $error_message_stats; ?></span>
            </div>
        <?php endif; ?>

        <div class="welcome-card">
            <h2>Selamat Datang di Sistem Manajemen Kontrakan</h2>
            <p>Kelola data kontrakan, penyewa, dan transaksi dengan mudah</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo number_format($dashboard_stats['total_bangunan']); ?></div>
                <div class="label">Total Bangunan</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($dashboard_stats['total_unit']); ?></div>
                <div class="label">Total Unit</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php echo number_format($dashboard_stats['total_penyewa_aktif']); ?></div>
                <div class="label">Penyewa Aktif</div>
            </div>
            <div class="stat-card">
                <div class="number">Rp
                    <?php echo number_format($dashboard_stats['total_transaksi_bulan_ini_amount'], 0, ',', '.'); ?>
                </div>
                <div class="label">Uang Masuk Bulan Ini</div>
            </div>
        </div>

        <div class="menu-grid">
            <?php if (isSuperAdmin()): ?>
                <a href="manage_users.php" class="menu-card super-admin-only">
                    <div class="icon"><i class="fas fa-users-cog"></i></div>
                    <h3>Manajemen User</h3>
                    <p>Kelola admin dan super admin sistem</p>
                    <span class="access-level access-super-admin">SUPER ADMIN ONLY</span>
                </a>
            <?php else: ?>
                <div class="menu-card super-admin-only disabled">
                    <div class="icon"><i class="fas fa-users-cog"></i></div>
                    <h3>Manajemen User</h3>
                    <p>Kelola admin dan super admin sistem</p>
                    <span class="access-level access-super-admin">SUPER ADMIN ONLY</span>
                </div>
            <?php endif; ?>

            <a href="pemilik.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-user-tie"></i></div>
                <h3>Data Pemilik</h3>
                <p>Kelola data pemilik bangunan kontrakan</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>

            <a href="bangunan.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-building"></i></div>
                <h3>Data Bangunan</h3>
                <p>Kelola data bangunan dan properti</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>

            <a href="unit.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-door-open"></i></div>
                <h3>Data Unit</h3>
                <p>Kelola unit-unit dalam bangunan</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>

            <a href="penyewa.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-users"></i></div>
                <h3>Data Penyewa</h3>
                <p>Kelola data penyewa dan profil mereka</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>

            <a href="sewa.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-handshake"></i></div>
                <h3>Kontrak Sewa</h3>
                <p>Kelola kontrak dan periode sewa</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>

            <a href="transaksi.php" class="menu-card admin-only">
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
                <h3>Transaksi</h3>
                <p>Kelola pembayaran dan transaksi</p>
                <span class="access-level access-admin">ADMIN+</span>
            </a>


        </div>
    </div>
</body>

</html>