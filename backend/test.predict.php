<?php
// This will show us EXACTLY what the LSTM is saying to the system
$output = shell_exec("python3 lstm_model.py 2>&1");
echo "<h1>LSTM Output:</h1>";
echo "<pre>$output</pre>";
?>