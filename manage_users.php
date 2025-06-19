<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: dashboard.php");
    exit();
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $username = trim($_POST['username']);
                $password = $_POST['password'];
                $role = trim($_POST['role']);

                if (!empty($username) && !empty($password) && !empty($role)) {
                    $check_query = "SELECT username FROM user WHERE username = ?";
                    $check_stmt = mysqli_prepare($conn, $check_query);
                    if ($check_stmt) {
                        mysqli_stmt_bind_param($check_stmt, "s", $username);
                        mysqli_stmt_execute($check_stmt);
                        $check_result = mysqli_stmt_get_result($check_stmt);

                        if (mysqli_num_rows($check_result) > 0) {
                            $message = "Username sudah ada!";
                            $message_type = "error";
                        } else {
                            $user_id = generateUUID();
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                            $insert_query = "INSERT INTO user (id_user, username, password, role) VALUES (?, ?, ?, ?)";
                            $insert_stmt = mysqli_prepare($conn, $insert_query);
                            if ($insert_stmt) {
                                mysqli_stmt_bind_param($insert_stmt, "ssss", $user_id, $username, $hashed_password, $role);

                                if (mysqli_stmt_execute($insert_stmt)) {
                                    $message = "User berhasil ditambahkan!";
                                    $message_type = "success";
                                } else {
                                    $message = "Gagal menambahkan user: " . $insert_stmt->error;
                                    $message_type = "error";
                                }
                                mysqli_stmt_close($insert_stmt);
                            } else {
                                $message = "Gagal menyiapkan statement tambah user: " . mysqli_error($conn);
                                $message_type = "error";
                            }
                        }
                        mysqli_stmt_close($check_stmt);
                    } else {
                        $message = "Gagal menyiapkan statement cek username: " . mysqli_error($conn);
                        $message_type = "error";
                    }
                } else {
                    $message = "Username, password, dan role wajib diisi!";
                    $message_type = "error";
                }
                break;

            case 'edit':
                $user_id = trim($_POST['user_id']);
                $username = trim($_POST['username']);
                $role = trim($_POST['role']);
                $password = $_POST['password'];

                if (!empty($user_id) && !empty($username) && !empty($role)) {
                    $check_query = "SELECT username FROM user WHERE username = ? AND id_user != ?";
                    $check_stmt = mysqli_prepare($conn, $check_query);
                    if ($check_stmt) {
                        mysqli_stmt_bind_param($check_stmt, "ss", $username, $user_id);
                        mysqli_stmt_execute($check_stmt);
                        $check_result = mysqli_stmt_get_result($check_stmt);

                        if (mysqli_num_rows($check_result) > 0) {
                            $message = "Username sudah digunakan oleh user lain!";
                            $message_type = "error";
                        } else {
                            $update_stmt = null;
                            if (!empty($password)) {
                                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                $update_query = "UPDATE user SET username = ?, password = ?, role = ? WHERE id_user = ?";
                                $update_stmt = mysqli_prepare($conn, $update_query);
                                if ($update_stmt) {
                                    mysqli_stmt_bind_param($update_stmt, "ssss", $username, $hashed_password, $role, $user_id);
                                }
                            } else {
                                $update_query = "UPDATE user SET username = ?, role = ? WHERE id_user = ?";
                                $update_stmt = mysqli_prepare($conn, $update_query);
                                if ($update_stmt) {
                                    mysqli_stmt_bind_param($update_stmt, "sss", $username, $role, $user_id);
                                }
                            }

                            if ($update_stmt) {
                                if (mysqli_stmt_execute($update_stmt)) {
                                    $message = "User berhasil diupdate!";
                                    $message_type = "success";
                                } else {
                                    $message = "Gagal mengupdate user: " . $update_stmt->error;
                                    $message_type = "error";
                                }
                                mysqli_stmt_close($update_stmt);
                            } else {
                                $message = "Gagal menyiapkan statement update user. Terjadi kesalahan internal.";
                                $message_type = "error";
                            }
                        }
                        mysqli_stmt_close($check_stmt);
                    } else {
                        $message = "Gagal menyiapkan statement cek username saat edit: " . mysqli_error($conn);
                        $message_type = "error";
                    }
                } else {
                    $message = "ID User, username, dan role wajib diisi untuk edit!";
                    $message_type = "error";
                }
                break;

            case 'delete':
                $user_id = trim($_POST['user_id']);

                if ($user_id === $_SESSION['user_id']) {
                    $message = "Anda tidak dapat menghapus akun sendiri!";
                    $message_type = "error";
                } else {
                    $delete_query = "DELETE FROM user WHERE id_user = ?";
                    $delete_stmt = mysqli_prepare($conn, $delete_query);
                    if ($delete_stmt) {
                        mysqli_stmt_bind_param($delete_stmt, "s", $user_id);

                        if (mysqli_stmt_execute($delete_stmt)) {
                            $message = "User berhasil dihapus!";
                            $message_type = "success";
                        } else {
                            $message = "Gagal menghapus user: " . $delete_stmt->error;
                            $message_type = "error";
                        }
                        mysqli_stmt_close($delete_stmt);
                    } else {
                        $message = "Gagal menyiapkan statement hapus user: " . mysqli_error($conn);
                        $message_type = "error";
                    }
                }
                break;
        }
    }
}

$users_query = "SELECT id_user, username, role FROM user ORDER BY role, username";
$users_result = mysqli_query($conn, $users_query);
if (!$users_result) {
    $message = "Gagal mengambil daftar user: " . mysqli_error($conn);
    $message_type = "error";
    $users_data = [];
} else {
    $users_data = mysqli_fetch_all($users_result, MYSQLI_ASSOC);
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen User - SiKontra</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style_manage.css">
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-users"></i> Manajemen User</h1>
            <a href="dashboard.php" class="back-button">
                <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
            </a>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="add-user-form">
            <h3><i class="fas fa-user-plus"></i> Tambah User Baru</h3>
            <form method="post">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label for="role">Role:</label>
                        <select id="role" name="role" required>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-plus"></i> Tambah User
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <table class="users-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users_data as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <span class="role-badge role-<?php echo $user['role']; ?>">
                                <?php echo $user['role'] === 'super_admin' ? 'Super Admin' : 'Admin'; ?>
                            </span>
                        </td>
                        <td class="actions">
                            <button class="btn btn-warning"
                                onclick="openEditModal('<?php echo $user['id_user']; ?>', '<?php echo htmlspecialchars($user['username']); ?>', '<?php echo $user['role']; ?>')">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <?php if ($user['id_user'] !== $_SESSION['user_id']): ?>
                                <form method="post" style="display: inline;"
                                    onsubmit="return confirm('Apakah Anda yakin ingin menghapus user ini?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id_user']; ?>">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-trash"></i> Hapus
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit User</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="edit_user_id" name="user_id">

                <div class="form-group">
                    <label for="edit_username">Username:</label>
                    <input type="text" id="edit_username" name="username" required>
                </div>

                <div class="form-group">
                    <label for="edit_password">Password Baru (kosongkan jika tidak ingin mengubah):</label>
                    <input type="password" id="edit_password" name="password">
                </div>

                <div class="form-group">
                    <label for="edit_role">Role:</label>
                    <select id="edit_role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddModal() {
            document.getElementById('addModal').style.display = 'block';
            document.getElementById('username').value = '';
            document.getElementById('password').value = '';
            document.getElementById('role').value = 'admin';
        }

        function openEditModal(userId, username, role) {
            document.getElementById('edit_user_id').value = userId;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_role').value = role;
            document.getElementById('edit_password').value = '';
            document.getElementById('editModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        window.onclick = function (event) {
            const addModal = document.getElementById('addModal');
            const editModal = document.getElementById('editModal');
            if (event.target === addModal) {
                closeModal('addModal');
            }
            if (event.target === editModal) {
                closeModal('editModal');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const alerts = document.querySelectorAll('.message');
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
    </script>
</body>

</html>