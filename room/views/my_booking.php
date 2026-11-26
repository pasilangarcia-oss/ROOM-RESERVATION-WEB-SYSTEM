<?php
session_start();
require_once __DIR__ . '/../config/helpers.php';
requireLogin();
header('Location: dashboard.php');
exit();
