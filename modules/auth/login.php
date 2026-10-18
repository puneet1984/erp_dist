<?php
define('SECURE_ACCESS', true);
define('SKIP_AUTH', true);
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../includes/session_config.php';

session_start();

$error = '';
$timeout = isset($_GET['timeout']) ? 'Your session has expired. Please login again.' : '';

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $conn = getDbConnection();
    $stmt = $conn->prepare('SELECT u.*, r.role_name, r.permissions 
                           FROM users u 
                           JOIN roles r ON u.role_id = r.role_id 
                           WHERE u.username = ?');

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    error_log('Login attempt - Username: '.$username);
    error_log('User found: '.($user ? 'Yes' : 'No'));
    if ($user) {
        error_log('Status: '.$user['status']);
        error_log('Stored hash: '.$user['password_hash']);

        if ('ACTIVE' === $user['status'] && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];
            $_SESSION['permissions'] = json_decode($user['permissions'], true);

            // Process stock messages after successful login
            try {
                require_once __DIR__.'/../../scripts/process_stock_messages.php';
            } catch (Exception $e) {
                error_log('Stock message processing error: '.$e->getMessage());
                // Continue with login even if stock processing fails
            }

            error_log('Login successful for user: '.$username);
            header('Location: ../dashboard/');
            exit;
        }
    }
	
    error_log('Login failed for user: '.$username);
    $error = 'Invalid username or password';
}
?>

<!DOCTYPE html>
<html>

<head>
	<title>Login - <?php echo APP_NAME; ?></title>
	<link rel="stylesheet" href="../../assets/css/login.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
		integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw=="
		crossorigin="anonymous" referrerpolicy="no-referrer" />

	<style>
		.password-group {
			position: relative;
		}

		.password-toggle {
			position: absolute;
			right: -5px;
			top: 50%;
			transform: translateY(-50%);
			cursor: pointer;
			user-select: none;
		}
	</style>
</head>

<body class="login-page">
	<div class="login-container">
		<div class="login-box">
			<h2><?php echo APP_NAME; ?></h2>

			<?php if ($timeout) { ?>
			<div class="timeout-message">
				<?php echo htmlspecialchars($timeout); ?>
			</div>
			<?php } ?>

			<?php if ($error) { ?>
			<div class="error-message">
				<?php echo htmlspecialchars($error); ?>
			</div>
			<?php } ?>

			<form method="POST" class="login-form">
				<div class="form-group">
					<label>Username</label>
					<input type="text" name="username" required autofocus autocomplete="username">
				</div>

				<div class="form-group">
					<label>Password</label>
					<div class="password-group">
						<input type="password" name="password" id="password" required autocomplete="current-password">
						<span class="password-toggle"><i class="fas fa-eye password-toggle-icon"></i></span>
					</div>
				</div>

				<button type="submit" class="btn-login">Login</button>
			</form>
		</div>
	</div>

	<script>
		document.addEventListener('DOMContentLoaded', function() {
			const passwordField = document.getElementById("password");
			const togglePassword = document.querySelector(".password-toggle i");

			togglePassword.addEventListener("click", function() {
				if (passwordField.type === "password") {
					passwordField.type = "text";
					togglePassword.classList.remove("fa-eye");
					togglePassword.classList.add("fa-eye-slash");
				} else {
					passwordField.type = "password";
					togglePassword.classList.remove("fa-eye-slash");
					togglePassword.classList.add("fa-eye");
				}
			});
		});
	</script>
</body>

</html>