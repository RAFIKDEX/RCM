<?php
require_once __DIR__ . "/auth.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $u = $_POST["username"] ?? "";
    $p = $_POST["password"] ?? "";

    if ($u === RCM_USER && $p === RCM_PASS) {
        $_SESSION["rcm_logged_in"] = true;
header("Location: /dashboard.php");
        exit;
    } else {
        $error = "Wrong username or password";
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>RCM Login</title>
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">

<style>
body{
  margin:0;
  font-family: Arial, sans-serif;
  height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;

  background:
    radial-gradient(circle at 1px 1px, #3d3d3d 1px, transparent 0),
        #5398d7;

  background-size: 10px 10px;
}

.box{
  width:400px;
  padding:30px 28px;

  background:#2c5f8f; /* أغمق من الخلفية */
  border:3px solid rgba(255,255,255,0.15);
  border-radius:20px;

  box-shadow:0 20px 40px rgba(0,0,0,.35);

  backdrop-filter: blur(6px);
}

h1{
  margin:0 0 28px 0;
  text-align:center;

  font-family: 'Orbitron', sans-serif;
  font-weight:900;
  font-size:40px;
  letter-spacing:6px;
  text-transform:uppercase;

  color:#ffffff;

  text-shadow:
    0 0 15px rgba(255,255,255,.4),
    0 0 30px rgba(255,255,255,.25);
}

  label{
    display:block;
    margin:15px 0 6px 0;
    font-weight:900;
    color:#333;
    font-size:15px;
  }

input{
  width:100%;
  padding:16px 0px;
  border-radius:40px;
  border:2px solid rgba(255,255,255,0.3);

  background:rgba(255,255,255,0.08);
  color:#ffffff;

  font-family: 'Orbitron', sans-serif;
  font-size:18px;
  letter-spacing:2px;

  text-align:center;   /* يخلي admin في النص */

  outline:none;
  transition:.2s;
}

input:focus{
  border-color:#ffffff;
  background:rgba(255,255,255,0.15);
}

button{
  margin-top:25px;
  width:100%;
  padding:16px;
  border-radius:40px;
  border:2px solid #ffffff;

  background:transparent;
  color:#ffffff;

  font-weight:900;
  font-size:16px;
  letter-spacing:2px;
  cursor:pointer;

  transition:.2s;
}

button:hover{
  background:#ffffff;
  color:#5398d7;
  box-shadow:0 0 20px rgba(255,255,255,.6);
}

button:active{
  transform:scale(0.97);
}

  .err{
    margin-top:14px;
    color:#b91c1c;
    font-weight:900;
    text-align:center;
  }
.password-wrapper{
  position:relative;
  width:100%;
}

/* خليك مع نفس ستايل input الطبيعي */
.password-wrapper input{
  width:100%;
  padding:16px 52px 16px 20px;  /* مساحة للعين من غير ما تكسر الشكل */
  border-radius:40px;
  border:2px solid rgba(255,255,255,0.3);
  background:rgba(255,255,255,0.08);
  color:#ffffff;

  font-family:'Orbitron', sans-serif;
  font-size:18px;
  letter-spacing:2px;
  text-align:center;

  outline:none;
  transition:.2s;

  box-sizing:border-box; /* ده أهم سطر عشان ما يطلعش برا */
}

.password-wrapper input:focus{
  border-color:#ffffff;
  background:rgba(255,255,255,0.15);
}

.toggle{
  position:absolute;
  right:18px;
  top:50%;
  transform:translateY(-50%);
  width:32px;
  height:32px;
  display:flex;
  align-items:center;
  justify-content:center;

  cursor:pointer;
  color:#ffffff;
  opacity:.75;
  user-select:none;

  /* يخليه “جوه” من غير دايرة غريبة */
  background:transparent;
  border-radius:50%;
}

.toggle:hover{
  opacity:1;
}




</style>
</head>
<body>
  <form class="box" method="post">
    <h1>RCM LOGIN</h1>

    <label>Username</label>
    <input name="username" autocomplete="username" required>

    <label>Password</label>
<div class="password-wrapper">
  <input type="password" id="password" name="password" required>
  <span class="toggle" onclick="togglePassword()">👁</span>
</div>

    <button type="submit">LOGIN</button>

    <?php if ($error): ?>
      <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>


  </form>
<script>
function togglePassword() {
  const pass = document.getElementById("password");
  if (pass.type === "password") {
    pass.type = "text";
  } else {
    pass.type = "password";
  }
}
</script>

</body>
</html>
