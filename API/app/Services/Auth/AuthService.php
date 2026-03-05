<?php

namespace App\Services\Auth;

use App\Contracts\Auth\Services\IAuthService;
use App\DTOs\Auth\Registro\RegisterDTO;
use App\DTOs\Auth\LoginDTO;
use App\Models\Usuario;
use App\Contracts\Auth\Repositories\ICuentaRepository;
use App\Contracts\Auth\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\JWTGuard;
use App\DTOs\Auth\recuperarContrasena\ClaveDto;
use App\DTOs\Auth\recuperarContrasena\CorreoDto;
use App\DTOs\Auth\recuperarContrasena\NuevaContrasenaDto;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Contracts\Auth\Services\IRegistroService;
use App\Exceptions\BusinessException;
use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;


/**
 * AuthService - Servicio de autenticación
 * 
 * RESPONSABILIDADES:
 * - Contiene toda la lógica de negocio relacionada con autenticación
 * - Coordina entre repositorios, modelos y validaciones
 * - Maneja la creación de tokens de Sanctum
 * - NO interactúa directamente con HTTP (eso es del Controller)
 * 
 * PATRÓN DE DISEÑO:
 * Este es un "Service" en la arquitectura de capas.
 * Los servicios contienen la lógica compleja que no pertenece
 * ni a los modelos ni a los controladores.
 * 
 * VENTAJAS:
 * - Reutilizable: puedes llamar estos métodos desde console, jobs, etc.
 * - Testeable: puedes hacer unit tests sin simular HTTP requests
 * - Mantenible: la lógica está en un solo lugar
 * - Cumple con Single Responsibility Principle (SOLID)
 */

class AuthService implements IAuthService
{
    /**
     * Constructor con inyección de dependencias 
     * 
     * Laravel automáticamente inyecta una instancia de UserRepository gracias
     * al RepositoryServiceProvider
     */
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private IRegistroService $registroService,
        private ICuentaRepository $cuentaRepository,
        private RecuperarContrasenaService $nuevaPasswordService,
        private JWTGuard $jwt
    )
    {}
    /**
     * Iniciar el proceso de registro, el usuario envia los datos, se obtiene el correo que el usuario ingreso
     * Se valida si el correo no esta en la base de datos y se envia el correo
     * 
     * @param RegisterDTO $dto - Datos del usuario a registrar
     * @return array - Resultado del proceso
     */
    public function validateGmailRestriction(string $email): void {
        $allowGmail = config('services.allow_gmail');
        $isGmail = str_ends_with(strtolower($email), '@gmail.com');

        if (!$allowGmail && $isGmail) {
            throw new ValidationException("El acceso para cuentas Gmail está restringido globalmente");
        }
    }

    public function iniciarRegistro(RegisterDTO $dto): array
    {
        $this->validateGmailRestriction($dto->email);

        // Validar que la cuenta institucional
        if(str_ends_with(strtolower($dto->email), "@gmail.com")) {
            if (!empty($dto->imagen)) {
                throw new BusinessException("Solo las cuentas con correo institucional pueden tener avatar", 422);
            }
        }

        $inicioProceso = $this->registroService->iniciarRegistro($dto->email, $dto->password);

        $cuenta_id = $inicioProceso['cuenta_id'];
        
        // Inicializar la ruta
        $ruta = null;

        if (!empty($dto->imagen)) {

            $file = request()->file('imagen');

            // Validar si la imagen es instacia de la clase UploadedFile para formatearla y subir solo su ruta
            if ($file instanceof UploadedFile) {
                $image = Image::read($file->getPathname())
                    ->resize(512, 512)
                    ->toWebp(90);

                $nombre = uniqid() . '.webp';
                $ruta = "tmp/registro/{$cuenta_id}/{$nombre}";

                // Enviar la imagen a una ruta temporal
                Storage::disk('public')->put($ruta, $image->toString());             
            }
        }

        // Retornar al authService los datos
        return [
            'cuenta_id'        => $cuenta_id,
            'expira_en'        => $inicioProceso['expira_en'],
            'datosEncriptados' => encrypt($dto->toArray($ruta)),
        ];
    }

    /**
     * Terminar el proceso de registro priorizando las transacciones para que no haya datos volando
     * @param string $datosEncriptado - Datos del formulario encriptados
     * @param string $clave - Código que le llega al usuario a su correo
     * @param int $cuenta_id - ID de la cuenta que recibe el usuario en la respuesta JSON anterior
     * @param string $dispositivo - Dispositivo de donde ingreso el usuario
     * @return array{user:Usuario, token: string, token_type: string, expires_in: int}
     */
        public function completarRegistro(string $datos, string $clave, int $id, string $dev): array
        {
            return $this->registroService->terminarRegistro($datos, $clave, $id, $dev);
        }

    /**
     * Inicio de sesión
     * 
     * PROCESO:
     * 1. Busca el usuario por email
     * 2. Verifica que exista
     * 3. Verifica que la contraseña sea correcta
     * 4. Verifica que el usuario esté activo (no eliminado)
     * 5. Revoca tokens anteriores del mismo dispositivo (seguridad)
     * 6. Crea un nuevo token
     * 7. Actualiza fecha de última actividad (RF010, RNF009)
     * 8. Retorna usuario y token
     * 
     * @param LoginDTO $dto - Credenciales de login
     * @return array{user: Usuario, token: string, login:string}
     * @throws ValidationException - Si las credenciales son inválidas
     */
    public function login(LoginDTO $dto): array
    {
        $this->validateGmailRestriction($dto->email);
        // 1. Buscar cuenta y validar credenciales
        $cuenta = $this->cuentaRepository->findByCorreo($dto->email);

        if (!$cuenta || !Hash::check($dto->password, $cuenta->password)) {
            // Usamos BusinessException para que el Handler la capture con el código 401
            throw new BusinessException('Correo o contraseña incorrectos.', 401);
        }

        // 2. Obtener perfil de usuario y validar estados/roles
        $user = $this->userRepository->findByIdCuenta($cuenta->id);

        if ($user->estado_id === 3) {
            throw new BusinessException('Tu cuenta ha sido eliminada. Contacta al soporte.', 403);
        }

        if ($user->rol_id === 1 && $dto->device_name === 'desktop') {
            throw new BusinessException('Acceso denegado desde escritorio para este rol.', 403);
        }

        // 3. Generar JWT y persistir sesión en una sola transacción
        return DB::transaction(function () use ($cuenta, $user, $dto) {
            
            $token = $this->jwt->fromUser($cuenta);
            $payload = $this->jwt->setToken($token)->getPayload();
            
            // Limpiar tokens anteriores en el mismo dispositivo e insertar el nuevo
            DB::table('tokens_de_sesion')
                ->where('cuenta_id', $cuenta->id)
                ->where('dispositivo', $dto->device_name)
                ->delete();

            DB::table('tokens_de_sesion')->insert([
                'cuenta_id'   => $cuenta->id,
                'dispositivo' => $dto->device_name,
                'jti'         => $payload->get('jti'),
                'ultimo_uso'  => now()
            ]);

            // Actualizar fecha de actividad reciente del usuario asociado a la cuenta
            DB::table('usuarios')
                ->where('cuenta_id', $cuenta->id)
                ->update(['fecha_reciente' => Carbon::now()]);

            return [
                'user'       => $user,
                'token'      => $token,
                'expires_in' => $this->jwt->factory()->getTTL() * 60
            ];
        });
    }

    /**
     * Iniciar el proceso de cambio de contraseña en donde se le enviara al 
     * Usuario un correo con el código de recuperación
     * @param CorreoDto $dto - Correo del usuario
     * @return array{message: string, correo: string, expira_en: string} $data
     */
    public function inicioNuevaPassword(CorreoDto $dto): array
    {
       return $this->nuevaPasswordService->iniciarProceso($dto->email);
    }

    public function validarClaveRecuperacion(int $cuenta_id, ClaveDto $dto): bool
    {
        return $this->nuevaPasswordService->verificarClaveContrasena($cuenta_id, $dto->clave);
    }

    /**
     * Lógica para cambiar el password del usuario
     * 
     * @param int $cuenta_id - Id de la cuenta a cambiar la contraseña
     * @param NuevaContrasenaDto $dto - Nueva contraseña del usuario 
     * @return bool
     */
    public function nuevaPassword(int $cuenta_id, NuevaContrasenaDto $dto): bool 
    {
        return $this->nuevaPasswordService->actualizarPassword($cuenta_id, $dto->password);
    }

    /**
     * Cerrar sesión del usuario 
     * 
     * PROCESO:
     * 1. Recibe el usaurio autenticado (Viene del middleware auth:sanctum)
     * 2. Revoca el token actual
     * 3. Opcionalmente puede revocar todos los tokens del usuario.
     * 
     * @param Usuario $user - Usuario autenticado
     * @param bool $allDevices - true, cierra sesión en todos los dispositivos
     * @return bool - true si se cerro correctamente
     */
    public function logout(bool $allDevices = false): void
    {
        // 1. Obtener datos del token actual
        // Si el token es inválido, getPayload() lanzará una excepción que el Handler capturará.
        $payload = $this->jwt->getPayload();
        $jti = $payload->get('jti');
        $cuentaId = $payload->get('sub');

        // 2. Limpieza de base de datos
        DB::transaction(fn() => 
            DB::table('tokens_de_sesion')
                ->where('cuenta_id', $cuentaId)
                ->when(!$allDevices, fn($query) => $query->where('jti', $jti))
                ->delete()
        );

        // 3. Invalidar el token en la lista negra (Blacklist) de JWT
        $this->jwt->invalidate($this->jwt->getToken());
    }
    /**
     * Refrescar token JWT
     * 
     * NUEVO MÉTODO (No existe en Sanctum)
     * 
     * PROPÓSITO:
     * Cuando un token está por expirar, el cliente puede "refrescarlo"
     * para obtener uno nuevo sin hacer login otra vez
     * 
     * PROCESO:
     * 1. Recibe el token actual (aunque esté casi expirado)
     * 2. Verifica que sea válido
     * 3. Genera un nuevo token con nueva fecha de expiración
     * 4. Opcionalmente invalida el token anterior (para que no se use)
     * 
     * CONFIGURACIÓN:
     * - refresh_ttl en config/jwt.php define cuánto tiempo después
     *   de expirado aún se puede refrescar (grace period)
     * - Por defecto: 2 semanas
     * 
     * USO EN EL FRONTEND:
     * Antes de que el token expire (ej: 5 min antes), hacer:
     * POST /api/auth/refresh con el token actual
     * 
     * @return array - Nuevo token y metadata
     * @throws JWTException - Si el token no se puede refrescar
     */
    public function refresh(): array
    {
        // 1. Refresca el token actual (si está vencido dentro del periodo de gracia, JWTAuth lo maneja)
        $newToken = $this->jwt->refresh();

        // 2. Extraer datos del nuevo token
        $payload = $this->jwt->setToken($newToken)->getPayload();

        // 3. Persistencia atómica
        DB::transaction(fn() => 
            DB::table('tokens_de_sesion')
                ->where('cuenta_id', $payload->get('sub'))
                ->update([
                    'jti' => $payload->get('jti'),
                    'ultimo_uso' => now(),
                ])
        );

        return [
            'token'      => $newToken,
            'token_type' => 'bearer',
            'expires_in' => $this->jwt->factory()->getTTL() * 60,
        ];
    }

    /**
     * Obtener información del usuario autenticado
     * 
     * @param Usuario $user - Usuario autenticado
     * @return Usuario - Mismo usuario pero con relaciones cargadas si es necesario
     */
    public function getCurrentUser(Usuario $user): Usuario
    {
        try {
            return $user;

        } catch (Exception $e) {
            Log::error('Error al obtener información del usuario', [
                'error' => $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine()
            ]);
            throw $e;
        }
    }
    
    /**
     * Verificar si un usuario esta "Recientemente conectado" (RNF009)
     * 
     * @param Usuario $user - Usuario a verificar
     * @return bool - true si estuvo activo
    */
    public function isRecentlyActive(Usuario $user): bool
    {
        // now()->subDay-> Retorna la fecha/hora de hace 24 horas
        // isAfter() Verifica si la fecha_reciente es porsterior a las 24 horas
        return Carbon::parse($user->fecha_reciente)->isAfter(now()->subDay());
    }
}
