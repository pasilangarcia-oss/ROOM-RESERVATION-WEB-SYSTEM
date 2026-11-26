<?php
session_start();
$_SESSION = [];
session_destroy();
session_start();
session_regenerate_id(true);
header('Location: views/index.php?message=Logged out successfully');
exit();
?>