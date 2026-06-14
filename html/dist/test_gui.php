<?php
session_start();

define("RCM_USER", "admin");
define("RCM_PASS", "1234");

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $u = $_POST["username"] ?? "";
    $p = $_POST["password"] ?? "";

    if ($u === RCM_USER && $p === RCM_PASS) {
        $_SESSION["rcm_logged_in"] = true;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Wrong username or password";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>RCM Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@700;900&display=swap" rel="stylesheet">

<style>
*{box-sizing:border-box}

body{
    margin:0;
    height:100vh;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;

    background:
      radial-gradient(circle at 1px 1px, #3d3d3d 1px, transparent 0),
      #5398d7;
    background-size: 10px 10px;

    font-family:Arial, sans-serif;
}

#eyesLayer{
    position:fixed;
    inset:0;
    pointer-events:none;
    z-index:1;
}

.login-box{
    position:relative;
    z-index:2;
    width:400px;
    padding:30px;
    border-radius:20px;
    background:#2c5f8f;
    border:3px solid rgba(255,255,255,0.15);
    box-shadow:0 20px 40px rgba(0,0,0,.35);
    backdrop-filter:blur(6px);
}

h1{
    font-family:'Orbitron', sans-serif;
    text-align:center;
    font-size:40px;
    letter-spacing:6px;
    color:#fff;
    margin-bottom:25px;
}

label{
    color:#0b2239;
    font-weight:900;
}

input{
    width:100%;
    padding:16px;
    border-radius:40px;
    border:2px solid rgba(255,255,255,0.3);
    background:rgba(255,255,255,0.08);
    color:#fff;
    text-align:center;
    margin:8px 0 15px;
    font-family:'Orbitron', sans-serif;
    letter-spacing:2px;
}

.password-wrap{ position:relative; }

#togglePassword{
    position:absolute;
    right:15px;
    top:50%;
    transform:translateY(-50%);
    border:none;
    background:none;
    font-size:20px;
    cursor:pointer;
    color:#fff;
}

button.submit{
    width:100%;
    padding:15px;
    border-radius:40px;
    border:2px solid #fff;
    background:transparent;
    color:#fff;
    font-weight:900;
    letter-spacing:2px;
    cursor:pointer;
}

.err{
    margin-top:10px;
    background:#fff;
    color:red;
    padding:8px;
    border-radius:10px;
    text-align:center;
}

/* eye */
.eye{
    position:absolute;
    width:110px;
    height:110px;
}

.eye svg{ width:100%; height:100%; }

.pupil{
    transition:transform .08s linear;
    transform-origin:center;
}

.eye.closed .lidTop,
.eye.closed .lidBottom{
    transform:translateY(0);
}

.lidTop{
    transform:translateY(-60px);
    transition:.2s;
}

.lidBottom{
    transform:translateY(60px);
    transition:.2s;
}
</style>
</head>
<body>

<div id="eyesLayer"></div>

<div class="login-box" id="loginBox">
<h1>RCM LOGIN</h1>

<form method="post">
<label>Username</label>
<input type="text" id="username" name="username" required>

<label>Password</label>
<div class="password-wrap">
<input type="password" id="password" name="password" required>
<button type="button" id="togglePassword">??</button>
</div>

<button class="submit">LOGIN</button>

<?php if($error): ?>
<div class="err"><?php echo $error; ?></div>
<?php endif; ?>
</form>
</div>

<script>
const eyesLayer = document.getElementById("eyesLayer");
const loginBox = document.getElementById("loginBox");
const username = document.getElementById("username");
const password = document.getElementById("password");
const toggleBtn = document.getElementById("togglePassword");

function eyeSVG(){
return `
<svg viewBox="0 0 220 220">
<path d="M25 110C52 68 84 48 110 48C136 48 168 68 195 110C168 152 136 172 110 172C84 172 52 152 25 110Z" fill="#2E6A90"/>
<path d="M45 110C65 82 86 70 110 70C134 70 155 82 175 110C155 138 134 150 110 150C86 150 65 138 45 110Z" fill="#7FE9F7"/>
<g class="pupil">
<circle cx="110" cy="110" r="32" fill="#284B78"/>
<circle cx="100" cy="100" r="8" fill="#CFF6FF"/>
</g>
<path class="lidTop" d="M25 110C52 68 84 48 110 48C136 48 168 68 195 110" fill="#EAF7FF"/>
<path class="lidBottom" d="M25 110C52 152 84 172 110 172C136 172 168 152 195 110" fill="#EAF7FF"/>
</svg>`;
}

function generateEyes(){
eyesLayer.innerHTML="";
const rect=loginBox.getBoundingClientRect();

for(let i=0;i<40;i++){
let eye=document.createElement("div");
eye.className="eye";
eye.innerHTML=eyeSVG();

let x,y;
do{
x=Math.random()*window.innerWidth;
y=Math.random()*window.innerHeight;
}while(
x>rect.left-120 &&
x<rect.right &&
y>rect.top-120 &&
y<rect.bottom
);

eye.style.left=x+"px";
eye.style.top=y+"px";
eye.style.transform=`rotate(${Math.random()*360}deg)`;

eyesLayer.appendChild(eye);
}
}

generateEyes();

function lookAt(el){
const rect=el.getBoundingClientRect();
const tx=rect.left+rect.width/2;
const ty=rect.top+rect.height/2;

document.querySelectorAll(".pupil").forEach(p=>{
const er=p.closest(".eye").getBoundingClientRect();
const ex=er.left+er.width/2;
const ey=er.top+er.height/2;

const dx=tx-ex;
const dy=ty-ey;
const ang=Math.atan2(dy,dx);

p.style.transform=`translate(${Math.cos(ang)*12}px,${Math.sin(ang)*12}px)`;
});
}

username.addEventListener("focus",()=>lookAt(username));
password.addEventListener("focus",()=>lookAt(password));
username.addEventListener("input",()=>lookAt(username));
password.addEventListener("input",()=>lookAt(password));

toggleBtn.addEventListener("click",()=>{
const isHidden=password.type==="password";
password.type=isHidden?"text":"password";
toggleBtn.textContent=isHidden?'??':'??';

document.querySelectorAll(".eye").forEach(e=>{
if(password.type==="text"){
e.classList.add("closed");
}else{
e.classList.remove("closed");
lookAt(password);
}
});
});
</script>

</body>
</html>