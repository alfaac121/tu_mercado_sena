<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\ProductoController; 
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EstadosController;
use App\Http\Controllers\Api\MensajeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/**
 * RUTAS PÚBLICAS (Sin autenticación)
 * Cualquiera puede entrar a ellas
 */
Route::prefix('auth')->group(function()  {      

    // POST api/auth/iniciar-registro
    // Inicia el proceso de registro en donde se le envia al usuario un código de verificación 
    // A su correo electronico
    Route::post('/iniciar-registro', [AuthController::class, 'iniciarRegistro']);


    // POST /api/auth/register
    // Valida que el código enviado sea correcto y si es asi
    // Registra al usuario en el sistema
    Route::post('/register', [AuthController::class, 'register']);

    // POST /api/auth/login
    // Inicia sesión y retornar un token
    Route::post('/login', [AuthController::class, 'login']);

    Route::prefix('recuperar-contrasena')->group(function() {
        /**
         * Endpoint que valida el correo del usuario en la base de datos y envia el 
         * Código de recuperación al correo del usuario
         * 
         * POST
         *  RUTA: /api/auth/recuperar-contrasena/validar-correo
         * 
         */
        Route::post('/validar-correo', [AuthController::class, 'iniciarProcesoPassword']);
    
        /**
         * Endpoint que valida el código que ingresa el usuario al front-end
         * 
         * POST
         * RUTA: /api/auth/recuperar-contrasena/validar-clave-recuperacion
         */
        Route::post('/validar-clave-recuperacion', [AuthController::class, 'validarClavePassword']);
        /**
         * Endpoint que recibe la nueva contraseña del usuario y actualiza en la base 
         * De datos
         * 
         *PATCH
         *RUTA: /api/auth/recuperar-contrasena/reestablecer-contrasena
         */
        Route::patch('/reestablecer-contrasena', [AuthController::class, 'reestablecerPassword']);
    });

});

/**
 * Listado de productos PÚBLICO (sin JWT).
 * Así todos ven los productos aunque el token falle o no se envíe; evita que "desaparezcan" al recargar.
 */
Route::get('/productos', [ProductoController::class, 'index']);

/**
 * RUTAS PROTEGIDAS (Requieren autenticación)
 * 
 * El middleware personalizado "jwtVerify" verifica el token.
 * 
 */
Route::middleware('jwtVerify')->group(function (){

    Route::middleware(['CheckGmailRestriction', 'throttle:api_usuario'])->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    
        // === EDITAR PERFIL ===
        Route::patch("/editar-perfil/{usuario}", [UsuarioController::class, 'update']);
    
        // === BLOQUEADOS ===
        Route::get('/bloqueados', [UsuarioController::class, 'obtenerBloqueadosPorUsuario']);
        Route::post('/bloqueados/{usuario}', [UsuarioController::class, 'bloquearUsuario']);
        Route::delete('/bloqueados/{usuario}', [UsuarioController::class, 'desbloquearUsuario']);
    
        // ========================================
        // === PRODUCTOS (RUTAS PROTEGIDAS) ===
        // ========================================
        
        Route::prefix('productos')->group(function () {
            /**
             * Buscar productos por texto en nombre o descripción
             * 
             * GET /api/productos/buscar?q=laptop&per_page=15
             */
            Route::get('/buscar', [ProductoController::class, 'buscar']);
            
            /**
             * Obtener productos de un vendedor específico
             * 
             * GET /api/productos/vendedor/{vendedorId}
             */
            Route::get('/vendedor/{vendedorId}', [ProductoController::class, 'porVendedor']);
            
            /**
             * Listar productos con filtros opcionales
             * 
             * GET /api/productos
             * Query params: ?categoria_id=1&subcategoria_id=5&integridad_id=1&vendedor_id=10&per_page=15
             */
            Route::get('/', [ProductoController::class, 'index']);
            
            /**
             * Obtener un producto específico por ID
             * 
             * GET /api/productos/{id}
             */
            Route::get('/{id}', [ProductoController::class, 'show']);
    
            /**
             * Crear un nuevo producto
             * 
             * POST /api/productos
             * Body (form-data):
             *   - nombre
             *   - descripcion
             *   - subcategoria_id
             *   - integridad_id
             *   - precio
             *   - disponibles
             *   - imagenes[] (opcional, máx 5) FALTA COMPROBAR IMÁGENES DESDE POSTMAN 
             */
            Route::post('/', [ProductoController::class, 'store']);
            
            /**
             * Actualizar un producto existente
             * 
             * PUT /api/productos/{id}
             * PATCH /api/productos/{id}
             * Body: igual que crear
             */
            Route::put('/{id}', [ProductoController::class, 'update']);
            Route::patch('/{id}', [ProductoController::class, 'update']);
            
            /**
             * Eliminar un producto
             * 
             * DELETE /api/productos/{id}
             */
            Route::delete('/{id}', [ProductoController::class, 'destroy']);
            
            /**
             * Cambiar el estado de un producto
             * 
             * PATCH /api/productos/{id}/estado
             * Body: { "estado_id": 1 }  // 1=activo, 2=invisible, 3=eliminado
             */
            Route::patch('/{id}/estado', [ProductoController::class, 'cambiarEstado']);
        });
    
        /**
         * Obtener los productos del usuario autenticado
         * 
         * GET /api/mis-productos
         */
        Route::get('/mis-productos', [ProductoController::class, 'misProductos']);
    
        // GET Index: Listar todos los chats del usuario autenticado
        // GET Show: Ver detalles de un chat específico (incluye mensajes)
        // PATCH: Update: api/chats/{chat} Marcar mensajes como leídos o actualizar información del chat
        // DELETE Destroy: api/chats/{chats} Eliminar un chat (opcional, dependiendo de la lógica de negocio)
        Route::resource('chats', ChatController::class)->only([
            'index', 'show', 'destroy'
        ]);
    
        // Rutas para concretar una compraventa
        Route::patch('chats/{chat}/iniciar-compraventas', [ChatController::class, 'iniciarCompraVenta']);
        Route::patch('chats/{chat}/terminar-compraventas', [ChatController::class, 'terminarCompraVenta']);
    
        // Rutas para devoluciones
        Route::patch('chats/{chat}/iniciar-devoluciones', [ChatController::class, 'iniciarDevolucion']);
        Route::patch('chats/{chat}/terminar-devoluciones', [ChatController::class, 'terminarDevolucion']);
        
        // RUTA: api/chats
        // Crea un nuevo chat entre el usuario autenticado y otro usuario (vendedor)
        // El middleware "CheckChatBlock" verifica si el usuario autenticado ha bloqueado al otro usuario o viceversa
        Route::post('productos/{producto}/chats', [ChatController::class, 'store']);//->middleware('CheckChatBlock');
        
        //RUTA: api/chats/{chat}/mensajes
        Route::post('chats/{chat}/mensajes', [MensajeController::class, 'store'])->middleware('CheckChatBlock');
        // RUTA: api/mensajes/{mensaje}
        Route::delete('mensajes/{mensaje}', [MensajeController::class, 'destroy']);
        
        Route::get('estados', [EstadosController::class, 'index']);

        Route::get('transferencias', [ChatController::class, 'transferencias']);

        Route::get('transferencias-filtros', [ChatController::class, 'filtrarTransferencias']);
    });
});


/**
 * RUTAS DE PRUEBA
 * 
 * GET /api/ping
 */
Route::get('/ping', function () {
    return response()->json([
        'message' => 'pong',
        'timestamp' => now()->toIso8601String()
    ]);
});