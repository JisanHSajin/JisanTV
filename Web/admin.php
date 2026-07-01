<?php
// ============================================================
// Admin Panel - Manage Payments
// ============================================================

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include "db.php";
include "config.php";

// ============================================================
// ADMIN LOGIN HANDLING
// ============================================================

// Check if admin is not logged in
if (!isset($_SESSION['admin_logged'])) {
    
    // Handle login attempt
    if (isset($_POST['admin_login'])) {
        if ($_POST['password'] == ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
            // Redirect to admin panel after successful login
            header("Location: admin.php");
            exit;
        } else {
            $login_message = "Wrong Admin Password!";
        }
    }
    
    // Display login form
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Admin Login</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
        <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
        <style>
            *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
            body{background:#0f0f0f;color:white;display:flex;justify-content:center;align-items:center;height:100vh;}
            .admin-login{background:#1a1a1a;padding:40px;border-radius:12px;width:350px;border:1px solid #00ffff33;text-align:center;}
            .admin-login h2{color:#00ffff;margin-bottom:25px;}
            .input-box{margin-bottom:18px;}
            .input-box input{width:100%;padding:12px;border-radius:8px;border:1px solid #333;background:#111;color:white;}
            .login-btn{width:100%;padding:12px;background:#00ffff;border:none;border-radius:8px;font-weight:bold;cursor:pointer;}
            .message{margin-top:15px;color:yellow;}
            .links{margin-top:20px;}
            .links a{color:#00ffff;text-decoration:none;}
            @media(max-width:420px){.admin-login{width:90%;padding:25px;}}
        </style>
    </head>
    <body>
    <div class="admin-login">
        <h2>Admin Login</h2>
        <form method="POST">
            <div class="input-box">
                <input type="password" name="password" placeholder="Admin Password" required>
            </div>
            <button type="submit" name="admin_login" class="login-btn">Login</button>
            <div class="message">'.($login_message ?? '').'</div>
        </form>
        <div class="links"><a href="home.php">Go to Home</a></div>
    </div>
    </body>
    </html>';
    exit;
}

// ============================================================
// ADMIN LOGOUT
// ============================================================

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// ============================================================
// APPROVE PAYMENT
// ============================================================

if (isset($_GET['approve'])) {
    $payment_id = (int)$_GET['approve'];
    
    // Fetch payment details using prepared statement
    $stmt = mysqli_prepare($conn, "SELECT * FROM payments WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $payment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $pay = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($pay) {
        $user_id = (int)$pay['user_id'];
        $amount = (float)$pay['amount'];
        
        // Determine subscription months based on payment amount
        // Using if-else for PHP 7.x compatibility
        if ($amount == PRICE_1_MONTH) {
            $months = 1;
        } elseif ($amount == PRICE_3_MONTH) {
            $months = 3;
        } elseif ($amount == PRICE_6_MONTH) {
            $months = 6;
        } else {
            $months = 0;
        }
        
        // Only proceed if amount matches a valid plan
        if ($months > 0) {
            // Calculate expiration date
            $expires = date("Y-m-d", strtotime("+$months month"));
            $plan_name = $months . " Month" . ($months > 1 ? "s" : "");
            
            // Start transaction to ensure data consistency
            mysqli_begin_transaction($conn);
            
            try {
                // Insert subscription
                $stmt1 = mysqli_prepare($conn, "INSERT INTO subscriptions (user_id, plan, expires_at, status) VALUES (?, ?, ?, 'active')");
                mysqli_stmt_bind_param($stmt1, "iss", $user_id, $plan_name, $expires);
                $sub_result = mysqli_stmt_execute($stmt1);
                mysqli_stmt_close($stmt1);
                
                // Update payment status to approved
                $stmt2 = mysqli_prepare($conn, "UPDATE payments SET status='approved' WHERE id = ?");
                mysqli_stmt_bind_param($stmt2, "i", $payment_id);
                $update_result = mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
                
                // Commit transaction if both queries succeeded
                if ($sub_result && $update_result) {
                    mysqli_commit($conn);
                    $_SESSION['admin_message'] = "Payment #$payment_id approved successfully!";
                } else {
                    mysqli_rollback($conn);
                    $_SESSION['admin_error'] = "Database error: " . mysqli_error($conn);
                }
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $_SESSION['admin_error'] = "Error: " . $e->getMessage();
            }
        } else {
            $_SESSION['admin_error'] = "Invalid amount: $amount does not match any plan.";
        }
    } else {
        $_SESSION['admin_error'] = "Payment not found!";
    }
    
    header("Location: admin.php");
    exit;
}

// ============================================================
// REJECT PAYMENT
// ============================================================

if (isset($_GET['reject'])) {
    $payment_id = (int)$_GET['reject'];
    
    // Use prepared statement to update payment status
    $stmt = mysqli_prepare($conn, "UPDATE payments SET status='rejected' WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $payment_id);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    
    if ($result) {
        $_SESSION['admin_message'] = "Payment #$payment_id rejected successfully!";
    } else {
        $_SESSION['admin_error'] = "Error rejecting payment: " . mysqli_error($conn);
    }
    
    header("Location: admin.php");
    exit;
}

// ============================================================
// FETCH PENDING PAYMENTS
// ============================================================

// Use prepared statement with JOIN to get user email
$query = "SELECT payments.*, users.email 
          FROM payments 
          JOIN users ON payments.user_id = users.id 
          WHERE status='pending' 
          ORDER BY id DESC";

$pending = mysqli_query($conn, $query);

// Check for query error
if (!$pending) {
    die("Database query failed: " . mysqli_error($conn));
}

// ============================================================
// DISPLAY ADMIN PANEL
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <link rel="shortcut icon" type="image/png" href="https://jisanhsajin.free.nf/wp-content/uploads/2025/11/cropped-Untitled-design-1-89x89.png">
    <style>
        /* Reset and base styles */
        *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
        body{background:#0f0f0f;color:white;padding:20px;min-height:100vh;}
        
        /* Header styles */
        h2{color:#00ffff;text-align:center;margin-bottom:25px;font-size:28px;}
        
        /* Table styles */
        .table-container{overflow-x:auto;margin-top:20px;}
        table{width:100%;border-collapse:collapse;background:#1a1a1a;border-radius:10px;overflow:hidden;min-width:600px;}
        th,td{padding:15px;text-align:center;border-bottom:1px solid #333;}
        th{background:#222;color:#00ffff;font-weight:bold;text-transform:uppercase;font-size:14px;}
        tr:hover{background:#111;}
        td{font-size:14px;}
        
        /* Button styles */
        .btn-logout{
            position:fixed;
            top:20px;
            right:20px;
            padding:10px 20px;
            border-radius:8px;
            background:#ff3333;
            color:white;
            text-decoration:none;
            font-weight:bold;
            transition:0.3s;
            z-index:100;
        }
        .btn-logout:hover{background:#cc0000;}
        
        .btn-home{
            position:fixed;
            top:20px;
            left:20px;
            padding:10px 20px;
            border-radius:8px;
            background:#00ffff;
            color:black;
            text-decoration:none;
            font-weight:bold;
            transition:0.3s;
            z-index:100;
        }
        .btn-home:hover{background:#00cccc;}
        
        /* Action links */
        .action-approve{
            color:#00ff88;
            text-decoration:none;
            font-weight:bold;
            padding:5px 10px;
            border-radius:4px;
            transition:0.3s;
        }
        .action-approve:hover{background:#00ff8822;}
        
        .action-reject{
            color:#ff4444;
            text-decoration:none;
            font-weight:bold;
            padding:5px 10px;
            border-radius:4px;
            transition:0.3s;
        }
        .action-reject:hover{background:#ff444422;}
        
        /* Status badge */
        .status-badge{
            display:inline-block;
            padding:4px 12px;
            border-radius:20px;
            font-size:12px;
            font-weight:bold;
            text-transform:uppercase;
        }
        .status-pending{background:#ffaa00;color:#000;}
        .status-approved{background:#00ff88;color:#000;}
        .status-rejected{background:#ff4444;color:#fff;}
        
        /* Message styles */
        .message-success{
            background:#00cc44;
            color:white;
            padding:12px 20px;
            border-radius:8px;
            margin-bottom:20px;
            text-align:center;
        }
        .message-error{
            background:#ff3333;
            color:white;
            padding:12px 20px;
            border-radius:8px;
            margin-bottom:20px;
            text-align:center;
        }
        
        /* Empty state */
        .empty-state{
            text-align:center;
            margin-top:50px;
            font-size:18px;
            color:#888;
        }
        .empty-state span{font-size:50px;display:block;margin-bottom:15px;}
        
        /* Responsive */
        @media(max-width:768px){
            body{padding:15px;}
            .btn-logout,.btn-home{padding:8px 15px;font-size:12px;}
            .btn-home{top:10px;left:10px;}
            .btn-logout{top:10px;right:10px;}
            h2{font-size:22px;margin-top:50px;}
            th,td{padding:10px 8px;font-size:12px;}
            table{min-width:500px;}
        }
        @media(max-width:480px){
            .btn-logout,.btn-home{padding:6px 12px;font-size:11px;}
            th,td{padding:8px 5px;font-size:11px;}
            table{min-width:400px;}
            .action-approve,.action-reject{font-size:11px;padding:3px 6px;}
        }
    </style>
</head>
<body>
    <!-- Navigation buttons -->
    <a href="?logout=1" class="btn-logout" onclick="return confirm('Are you sure you want to logout?')">🚪 Logout</a>
    <a href="home.php" class="btn-home">🏠 Home</a>
    
    <h2>📋 Pending Payments</h2>
    
    <!-- Display messages -->
    <?php if(isset($_SESSION['admin_message'])): ?>
        <div class="message-success">
            ✅ <?php echo htmlspecialchars($_SESSION['admin_message']); ?>
        </div>
        <?php unset($_SESSION['admin_message']); ?>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['admin_error'])): ?>
        <div class="message-error">
            ❌ <?php echo htmlspecialchars($_SESSION['admin_error']); ?>
        </div>
        <?php unset($_SESSION['admin_error']); ?>
    <?php endif; ?>
    
    <!-- Payment table -->
    <?php if(mysqli_num_rows($pending) == 0): ?>
        <div class="empty-state">
            <span>🎉</span>
            No pending payments to review.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User Email</th>
                        <th>Transaction ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($pending)): ?>
                    <tr>
                        <td><strong>#<?php echo $row['id']; ?></strong></td>
                        <td><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><code><?php echo htmlspecialchars($row['trxid']); ?></code></td>
                        <td><strong><?php echo number_format($row['amount'], 2); ?> ৳</strong></td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower($row['status']); ?>">
                                <?php echo strtoupper($row['status']); ?>
                            </span>
                        </td>
                        <td>
                            <a href="?approve=<?php echo $row['id']; ?>" 
                               class="action-approve" 
                               onclick="return confirm('Approve payment #<?php echo $row['id']; ?> from <?php echo htmlspecialchars($row['email']); ?>?')">
                                ✅ Approve
                            </a> 
                            |
                            <a href="?reject=<?php echo $row['id']; ?>" 
                               class="action-reject" 
                               onclick="return confirm('Reject payment #<?php echo $row['id']; ?> from <?php echo htmlspecialchars($row['email']); ?>?')">
                                ❌ Reject
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary -->
        <div style="margin-top:20px;text-align:center;color:#888;font-size:14px;">
            Total pending: <strong style="color:#ffaa00;"><?php echo mysqli_num_rows($pending); ?></strong> payments
        </div>
    <?php endif; ?>
    
</body>
</html>