<?php
require 'config/auth.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
header('Location: ' . roleHome($_SESSION['role']));
exit;
