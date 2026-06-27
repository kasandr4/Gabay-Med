<?php
// logout.php
// Destroys the session, logging the user out, then sends them back to the homepage.

session_start();
session_unset();    // clear all session variables
session_destroy();  // destroy the session itself

header("Location: index.php");
exit;
