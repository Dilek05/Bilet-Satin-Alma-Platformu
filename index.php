<?php
ob_start();
session_start();

$redirect_url = 'sayfalar/ana-sayfa.php';
if (!headers_sent()) {
    header('Location: ' . $redirect_url);
    exit();
} else {
    echo '<script type="text/javascript">';
    echo 'window.location.href="' . $redirect_url . '";';
    echo '</script>';
    echo '<noscript>';
    echo '<meta http-equiv="refresh" content="0;url=' . $redirect_url . '" />';
    echo '</noscript>';
    exit();
}