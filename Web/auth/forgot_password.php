<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_api.php';
forceLightTheme();

$msg = '';
$error = '';
$step = 1; // 1: correo, 2: nueva contraseña (PHP) | 1: correo, 2: código, 3: nueva contraseña (Laravel)

$useLaravelAuth = isUsingLaravelApi();

if (!$useLaravelAuth) {
    if (isset($_SESSION['recuperar_cuenta_id'])) $step = 2;
    if (isset($_GET['cancelar'])) {
        unset($_SESSION['recuperar_cuenta_id'], $_SESSION['recuperar_email']);
        header("Location: forgot_password.php");
        exit();
    }
}

if (!$useLaravelAuth && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn = getDBConnection();
    if (isset($_POST['email']) && !isset($_POST['password'])) {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) $error = "El correo es obligatorio";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Correo inválido";
        else {
            $stmt = $conn->prepare("SELECT id FROM cuentas WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $cuenta = $result->fetch_assoc();
                $_SESSION['recuperar_cuenta_id'] = $cuenta['id'];
                $_SESSION['recuperar_email'] = $email;
                $step = 2;
                $msg = "Correo verificado. Ahora ingresa tu nueva contraseña.";
            } else {
                $error = "No existe una cuenta con ese correo.";
            }
            $stmt->close();
        }
    } elseif (isset($_POST['password'])) {
        $password = $_POST['password'];
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        $cuentaId = $_SESSION['recuperar_cuenta_id'] ?? null;
        if (!$cuentaId) { $error = "Sesión expirada. Inicia el proceso de nuevo."; $step = 1; }
        elseif (empty($password) || strlen($password) < 8) { $error = "La contraseña debe tener al menos 8 caracteres"; $step = 2; }
        elseif ($password !== $passwordConfirm) { $error = "Las contraseñas no coinciden"; $step = 2; }
        else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE cuentas SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $passwordHash, $cuentaId);
            if ($stmt->execute()) {
                unset($_SESSION['recuperar_cuenta_id'], $_SESSION['recuperar_email']);
                $conn->close();
                header("Location: login.php?password_changed=1");
                exit();
            }
            $stmt->close();
            $error = "Error al restablecer la contraseña";
            $step = 2;
        }
    }
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Recuperar contraseña - Tu Mercado SENA</title>
    <link rel="stylesheet" href="<?= getBaseUrl() ?>styles.css?v=<?= time(); ?>">
    <style>
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            color: #666;
        }
        .step.active {
            background: var(--primary-color, #007bff);
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">Recuperar contraseña</h1>
<?php if ($useLaravelAuth): ?>
            <div class="step-indicator">
                <div class="step active" id="fpStep1">1</div>
                <div class="step" id="fpStep2">2</div>
                <div class="step" id="fpStep3">3</div>
            </div>
            <div id="fpError" class="error-message" style="display:none;"></div>
            <div id="fpMsg" class="success-message" style="display:none;"></div>
            <!-- Paso 1: correo -->
            <div id="fpWrap1">
                <p style="text-align: center; margin-bottom: 15px; color: #666;">Ingresa tu correo @soy.sena.edu.co. Te enviaremos un código.</p>
                <form id="fpForm1">
                    <div class="form-group">
                        <label for="fp_email">Correo Electrónico</label>
                        <input id="fp_email" type="email" placeholder="tu@soy.sena.edu.co" required>
                    </div>
                    <button type="submit" class="btn-primary">Enviar código</button>
                </form>
            </div>
            <!-- Paso 2: código -->
            <div id="fpWrap2" style="display:none;">
                <p style="text-align: center; margin-bottom: 15px; color: #666;">Revisa tu correo e ingresa el código de 6 caracteres.</p>
                <form id="fpForm2">
                    <div class="form-group">
                        <label for="fp_clave">Código</label>
                        <input id="fp_clave" type="text" maxlength="6" pattern="[A-Za-z0-9]{6}" placeholder="XXXXXX" required>
                    </div>
                    <button type="submit" class="btn-primary">Verificar código</button>
                </form>
            </div>
            <!-- Paso 3: nueva contraseña -->
            <div id="fpWrap3" style="display:none;">
                <p style="text-align: center; margin-bottom: 15px; color: #666;">Ingresa tu nueva contraseña.</p>
                <form id="fpForm3">
                    <div class="form-group">
                        <label for="fp_password">Nueva Contraseña</label>
                        <input id="fp_password" type="password" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label for="fp_password_confirm">Confirmar Contraseña</label>
                        <input id="fp_password_confirm" type="password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn-primary">Cambiar contraseña</button>
                </form>
            </div>
            <p class="auth-link"><a href="login.php">← Volver al login</a></p>
<?php else: ?>
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">1</div>
                <div class="step <?php echo $step >= 2 ? 'active' : ''; ?>">2</div>
            </div>
            <?php if (!empty($msg)): ?>
                <div class="success-message"><?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="error-message"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($step === 1): ?>
            <form method="POST" action="forgot_password.php">
                <p style="text-align: center; margin-bottom: 15px; color: #666;">Ingresa tu correo electrónico para restablecer tu contraseña.</p>
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input id="email" type="email" name="email" placeholder="tu@soy.sena.edu.co" required>
                </div>
                <button type="submit" class="btn-primary">Verificar correo</button>
                <p class="auth-link"><a href="login.php">← Volver al login</a></p>
            </form>
            <?php else: ?>
            <form method="POST" action="forgot_password.php">
                <p style="text-align: center; margin-bottom: 15px; color: #666;">
                    Restableciendo contraseña para:<br>
                    <strong><?php echo htmlspecialchars($_SESSION['recuperar_email'] ?? ''); ?></strong>
                </p>
                <div class="form-group">
                    <label for="password">Nueva Contraseña</label>
                    <input id="password" type="password" name="password" minlength="8" required>
                    <small>Mínimo 8 caracteres</small>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirmar Contraseña</label>
                    <input id="password_confirm" type="password" name="password_confirm" minlength="8" required>
                </div>
                <button type="submit" class="btn-primary">Cambiar contraseña</button>
                <p class="auth-link"><a href="forgot_password.php?cancelar=1">Cancelar</a></p>
            </form>
            <?php endif; ?>
<?php endif; ?>
        </div>
    </div>
<?php if ($useLaravelAuth): ?>
    <script src="<?= getBaseUrl() ?>js/api-config.js"></script>
    <script>
        window.BASE_URL = <?= json_encode(getBaseUrl()) ?>;
        var fpCuentaId = null;
        var apiBase = typeof API_CONFIG !== 'undefined' ? API_CONFIG.LARAVEL_URL : 'http://localhost:8000/api/';

        function fpShowStep(n) {
            document.getElementById('fpWrap1').style.display = n === 1 ? 'block' : 'none';
            document.getElementById('fpWrap2').style.display = n === 2 ? 'block' : 'none';
            document.getElementById('fpWrap3').style.display = n === 3 ? 'block' : 'none';
            document.getElementById('fpStep1').className = 'step' + (n >= 1 ? (n > 1 ? ' completed' : ' active') : '');
            document.getElementById('fpStep2').className = 'step' + (n >= 2 ? (n > 2 ? ' completed' : ' active') : '');
            document.getElementById('fpStep3').className = 'step' + (n >= 3 ? ' active' : '');
            document.getElementById('fpError').style.display = 'none';
        }

        document.getElementById('fpForm1').addEventListener('submit', async function(e) {
            e.preventDefault();
            var email = document.getElementById('fp_email').value.trim();
            var err = document.getElementById('fpError');
            err.style.display = 'none';
            try {
                var r = await fetch(apiBase + 'auth/recuperar-contrasena/validar-correo', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email: email })
                });
                var data = await r.json();
                var cuentaId = data.cuenta_id || (data.data && data.data.cuenta_id);
                if (cuentaId) {
                    fpCuentaId = cuentaId;
                    fpShowStep(2);
                } else {
                    err.textContent = data.message || (data.errors && Object.values(data.errors).flat().join(' ')) || 'Error al enviar el código';
                    err.style.display = 'block';
                }
            } catch (x) {
                err.textContent = 'Error de conexión. Comprueba que la API Laravel esté en marcha.';
                err.style.display = 'block';
            }
        });

        document.getElementById('fpForm2').addEventListener('submit', async function(e) {
            e.preventDefault();
            var clave = document.getElementById('fp_clave').value.trim();
            var err = document.getElementById('fpError');
            err.style.display = 'none';
            if (!fpCuentaId) { err.textContent = 'Sesión expirada. Vuelve a empezar.'; err.style.display = 'block'; return; }
            try {
                var r = await fetch(apiBase + 'auth/recuperar-contrasena/validar-clave-recuperacion', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cuenta_id: fpCuentaId, clave: clave })
                });
                var data = await r.json();
                var ok = (data.success && data.clave_verificada) || data.status === 'success';
                if (ok) {
                    fpShowStep(3);
                } else {
                    err.textContent = data.message || (data.errors && Object.values(data.errors).flat().join(' ')) || 'Código incorrecto o expirado';
                    err.style.display = 'block';
                }
            } catch (x) {
                err.textContent = 'Error de conexión.';
                err.style.display = 'block';
            }
        });

        document.getElementById('fpForm3').addEventListener('submit', async function(e) {
            e.preventDefault();
            var password = document.getElementById('fp_password').value;
            var password_confirm = document.getElementById('fp_password_confirm').value;
            var err = document.getElementById('fpError');
            err.style.display = 'none';
            if (password !== password_confirm) { err.textContent = 'Las contraseñas no coinciden'; err.style.display = 'block'; return; }
            if (!fpCuentaId) { err.textContent = 'Sesión expirada.'; err.style.display = 'block'; return; }
            try {
                var r = await fetch(apiBase + 'auth/recuperar-contrasena/reestablecer-contrasena', {
                    method: 'PATCH',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cuenta_id: fpCuentaId, password: password, password_confirmation: password_confirm })
                });
                var data = await r.json();
                var ok = r.ok && (data.success || data.status === 'success');
                if (ok) {
                    window.location.href = (window.BASE_URL || '') + 'auth/login.php?password_changed=1';
                } else {
                    err.textContent = data.message || (data.errors && Object.values(data.errors).flat().join(' ')) || 'Error al restablecer';
                    err.style.display = 'block';
                }
            } catch (x) {
                err.textContent = 'Error de conexión.';
                err.style.display = 'block';
            }
        });
    </script>
<?php endif; ?>
</body>
</html>
