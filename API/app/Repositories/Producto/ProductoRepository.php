<?php

namespace App\Repositories\Producto;

use App\Contracts\Producto\Repositories\IProductoRepository;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class ProductoRepository implements IProductoRepository
{
    /**
     * Id del usuario (Usuario) actual. El guard JWT usa Cuenta, por eso Auth::id() es cuenta_id.
     * En productos y bloqueados se usan usuario_id.
     */
    protected function currentUsuarioId(): ?int
    {
        $user = Auth::user();
        return $user && $user->usuario ? (int) $user->usuario->id : null;
    }

    /**
     * Crea un nuevo producto
     */
    public function crear(array $data): Producto
    {
        // El estado por defecto es 1 (activo)
        $data['estado_id'] = $data['estado_id'] ?? 1;
        
        unset($data['fecha_actualiza']); // remover si viene en el array
        
        return Producto::create($data);
    }

    /**
     * Actualiza un producto existente
     */
    public function actualizar(int $id, array $data): Producto
    {
        $producto = Producto::findOrFail($id);
        $data['fecha_actualiza'] = now();
        $producto->update($data);
        $producto->refresh();
        
        return $producto;
    }

    /**
     * Obtiene un producto por su ID con relaciones opcionales
     */
    public function obtenerPorId(int $id, array $relaciones = []): ?Producto
    {
        $query = Producto::query();

        // Aplicar filtro de bloqueados si hay usuario autenticado
        if (Auth::check()) {
            $query = $this->aplicarFiltroBloqueados($query);
        }

        if (!empty($relaciones)) {
            $query->with($relaciones);
        }

        return $query->find($id);
    }

    /**
     * Obtiene productos con paginación y filtros
     */
    public function obtenerConFiltros(array $filtros = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Producto::with(['vendedor', 'subcategoria.categoria', 'integridad', 'estado', 'fotos']);

        //Aplicar filtro de bloqueados
        if (Auth::check()) {
            $query = $this->aplicarFiltroBloqueados($query);
        }

        // Filtro por estado (por defecto solo activos)
        if (isset($filtros['estado_id'])) {
            $query->where('estado_id', $filtros['estado_id']);
        } else {
            $query->activos();
        }

        // Filtro por categoría
        if (isset($filtros['categoria_id'])) {
            $query->porCategoria($filtros['categoria_id']);
        }

        // Filtro por subcategoría
        if (isset($filtros['subcategoria_id'])) {
            $query->where('subcategoria_id', $filtros['subcategoria_id']);
        }

        // Filtro por integridad (nuevo, usado, etc.)
        if (isset($filtros['integridad_id'])) {
            $query->where('integridad_id', $filtros['integridad_id']);
        }

        // Filtro por vendedor
        if (isset($filtros['vendedor_id'])) {
            $query->porVendedor($filtros['vendedor_id']);
        }

        // Excluir mis propios productos en el listado general (vendedor_id es usuario_id)
        $miUsuarioId = $this->currentUsuarioId();
        if ($miUsuarioId !== null && !isset($filtros['vendedor_id'])) {
            $query->where('vendedor_id', '<>', $miUsuarioId);
        }

        // Ordenamiento
        $orderBy = $filtros['order_by'] ?? 'fecha_registro';
        $orderDirection = $filtros['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query->paginate($perPage);
    }

    /**
     * Obtiene los productos de un vendedor específico
     */
    public function obtenerPorVendedor(int $vendedorId, array $relaciones = []): Collection
    {
        $query = Producto::porVendedor($vendedorId);

        // Si el usuario autenticado no es el vendedor, aplicar filtro de bloqueados
        $miUsuarioId = $this->currentUsuarioId();
        if ($miUsuarioId !== null && $miUsuarioId !== $vendedorId) {
            $query = $this->aplicarFiltroBloqueados($query);
        }

        if (!empty($relaciones)) {
            $query->with($relaciones);
        }

        return $query->get();
    }

    /**
     * Cambia el estado de un producto
     */
    public function cambiarEstado(int $id, int $estadoId): bool
    {
        return Producto::where('id', $id)->update([
            'estado_id' => $estadoId,
        ]) > 0;
    }

    /**
     * Elimina (lógicamente) un producto cambiando su estado a eliminado (3)
     */
    public function eliminar(int $id): bool
    {
        return $this->cambiarEstado($id, 3); // 3 = eliminado según BD
    }

    /**
     * Verifica si un producto pertenece a un vendedor
     */
    public function perteneceAVendedor(int $productoId, int $vendedorId): bool
    {
        return Producto::where('id', $productoId)
            ->where('vendedor_id', $vendedorId)
            ->exists();
    }

    /**
     * Actualiza la fecha de actualización del producto
     */
    public function actualizarFechaActualizacion(int $id): bool
    {
        return Producto::where('id', $id)
            ->update(['fecha_actualiza' => now()]) > 0;
    }

    /**
     * Busca productos por texto en nombre o descripción
     */
    public function buscar(string $busqueda, int $perPage = 15): LengthAwarePaginator
    {
        $query = Producto::with(['vendedor', 'subcategoria.categoria', 'integridad', 'estado', 'fotos'])
            ->activos()
            ->where(function($q) use ($busqueda) {
                $q->where('nombre', 'like', "%{$busqueda}%")
                  ->orWhere('descripcion', 'like', "%{$busqueda}%");
            });

        // Aplicar filtro de bloqueados
        if (Auth::check()) {
            $query = $this->aplicarFiltroBloqueados($query);
        }

        // Excluir mis propios productos en la búsqueda general
        $miUsuarioId = $this->currentUsuarioId();
        if ($miUsuarioId !== null) {
            $query->where('vendedor_id', '<>', $miUsuarioId);
        }

        return $query->orderBy('fecha_registro', 'desc')->paginate($perPage);
    }

    /**
     * Aplica el filtro para excluir productos de usuarios bloqueados
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function aplicarFiltroBloqueados($query)
    {
        $usuarioId = $this->currentUsuarioId();
        if ($usuarioId === null) {
            return $query;
        }

        return $query->whereNotIn('vendedor_id', function ($subQuery) use ($usuarioId) {
            $subQuery->select('bloqueado_id')
                ->from('bloqueados')
                ->where('bloqueador_id', $usuarioId);
        })->whereNotIn('vendedor_id', function ($subQuery) use ($usuarioId) {
            // También excluir productos de usuarios que me han bloqueado
            $subQuery->select('bloqueador_id')
                ->from('bloqueados')
                ->where('bloqueado_id', $usuarioId);
        });
    }
}