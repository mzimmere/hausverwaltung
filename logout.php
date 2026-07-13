<?php
require_once 'config/config.php';
require_once 'config/auth.php';
session_unset();
session_destroy();
header('Location: login.php');
exit;
