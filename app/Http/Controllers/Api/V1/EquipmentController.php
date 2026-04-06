<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Models\EquipmentType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    // ── Equipment Types ──────────────────────────────────────

    public function types(): JsonResponse
    {
        return response()->json(EquipmentType::withCount('equipments')->orderBy('nom')->get());
    }

    public function storeType(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'actif' => 'nullable|boolean',
        ]);
        return response()->json(EquipmentType::create($validated), 201);
    }

    public function updateType(Request $request, EquipmentType $equipmentType): JsonResponse
    {
        $equipmentType->update($request->validate([
            'nom' => 'sometimes|string|max:255',
            'icon' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:500',
            'actif' => 'nullable|boolean',
        ]));
        return response()->json($equipmentType);
    }

    public function destroyType(EquipmentType $equipmentType): JsonResponse
    {
        $equipmentType->delete();
        return response()->json(null, 204);
    }

    // ── Equipments ───────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Equipment::with(['type', 'collaborateur', 'assignedBy']);

        if ($request->has('etat')) {
            $query->where('etat', $request->etat);
        }
        if ($request->has('equipment_type_id')) {
            $query->where('equipment_type_id', $request->equipment_type_id);
        }
        if ($request->has('collaborateur_id')) {
            $query->where('collaborateur_id', $request->collaborateur_id);
        }

        return response()->json($query->orderByDesc('updated_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'equipment_type_id' => 'required|exists:equipment_types,id',
            'nom' => 'required|string|max:255',
            'numero_serie' => 'nullable|string|max:255',
            'marque' => 'nullable|string|max:255',
            'modele' => 'nullable|string|max:255',
            'etat' => 'nullable|in:disponible,attribue,en_commande,en_reparation,retire',
            'date_achat' => 'nullable|date',
            'valeur' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $equipment = Equipment::create($validated);
        return response()->json($equipment->load(['type']), 201);
    }

    public function show(Equipment $equipment): JsonResponse
    {
        return response()->json($equipment->load(['type', 'collaborateur', 'assignedBy']));
    }

    public function update(Request $request, Equipment $equipment): JsonResponse
    {
        $equipment->update($request->validate([
            'equipment_type_id' => 'sometimes|exists:equipment_types,id',
            'nom' => 'sometimes|string|max:255',
            'numero_serie' => 'nullable|string|max:255',
            'marque' => 'nullable|string|max:255',
            'modele' => 'nullable|string|max:255',
            'etat' => 'nullable|in:disponible,attribue,en_commande,en_reparation,retire',
            'date_achat' => 'nullable|date',
            'valeur' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]));
        return response()->json($equipment->load(['type', 'collaborateur']));
    }

    public function destroy(Equipment $equipment): JsonResponse
    {
        $equipment->delete();
        return response()->json(null, 204);
    }

    // ── Assignment ───────────────────────────────────────────

    public function assign(Request $request, Equipment $equipment): JsonResponse
    {
        $request->validate(['collaborateur_id' => 'required|exists:collaborateurs,id']);

        $equipment->update([
            'collaborateur_id' => $request->collaborateur_id,
            'assigned_by' => auth()->id(),
            'assigned_at' => now(),
            'returned_at' => null,
            'etat' => 'attribue',
        ]);

        return response()->json($equipment->load(['type', 'collaborateur', 'assignedBy']));
    }

    public function unassign(Equipment $equipment): JsonResponse
    {
        $equipment->update([
            'collaborateur_id' => null,
            'returned_at' => now(),
            'etat' => 'disponible',
        ]);

        return response()->json($equipment->load(['type']));
    }

    // ── Stats ────────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $total = Equipment::count();
        $disponible = Equipment::where('etat', 'disponible')->count();
        $attribue = Equipment::where('etat', 'attribue')->count();
        $enCommande = Equipment::where('etat', 'en_commande')->count();
        $enReparation = Equipment::where('etat', 'en_reparation')->count();
        $retire = Equipment::where('etat', 'retire')->count();
        $valeurTotale = Equipment::whereNotNull('valeur')->sum('valeur');

        return response()->json(compact('total', 'disponible', 'attribue', 'enCommande', 'enReparation', 'retire', 'valeurTotale'));
    }

    // ── Packages ─────────────────────────────────────────────

    public function packages(): JsonResponse
    {
        return response()->json(
            \App\Models\EquipmentPackage::with(['items.type'])->orderBy('nom')->get()
        );
    }

    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'required|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'couleur' => 'nullable|string|max:20',
            'actif' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.equipment_type_id' => 'required|exists:equipment_types,id',
            'items.*.quantite' => 'nullable|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        $package = \App\Models\EquipmentPackage::create([
            'nom' => $validated['nom'],
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? 'package',
            'couleur' => $validated['couleur'] ?? '#C2185B',
            'actif' => $validated['actif'] ?? true,
        ]);

        foreach ($validated['items'] as $item) {
            $package->items()->create($item);
        }

        return response()->json($package->load('items.type'), 201);
    }

    public function updatePackage(Request $request, \App\Models\EquipmentPackage $equipmentPackage): JsonResponse
    {
        $validated = $request->validate([
            'nom' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:50',
            'couleur' => 'nullable|string|max:20',
            'actif' => 'nullable|boolean',
            'items' => 'nullable|array',
            'items.*.equipment_type_id' => 'required|exists:equipment_types,id',
            'items.*.quantite' => 'nullable|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        $equipmentPackage->update(collect($validated)->except('items')->toArray());

        if (isset($validated['items'])) {
            $equipmentPackage->items()->delete();
            foreach ($validated['items'] as $item) {
                $equipmentPackage->items()->create($item);
            }
        }

        return response()->json($equipmentPackage->load('items.type'));
    }

    public function destroyPackage(\App\Models\EquipmentPackage $equipmentPackage): JsonResponse
    {
        $equipmentPackage->delete();
        return response()->json(null, 204);
    }

    /**
     * Provision a package for a collaborateur — creates equipment items for each package item.
     */
    public function provisionPackage(Request $request, \App\Models\EquipmentPackage $equipmentPackage): JsonResponse
    {
        $request->validate(['collaborateur_id' => 'required|exists:collaborateurs,id']);

        $created = [];
        foreach ($equipmentPackage->items()->with('type')->get() as $item) {
            for ($i = 0; $i < ($item->quantite ?? 1); $i++) {
                $equip = Equipment::create([
                    'equipment_type_id' => $item->equipment_type_id,
                    'nom' => $item->type->nom . ($item->notes ? " — {$item->notes}" : ''),
                    'etat' => 'attribue',
                    'collaborateur_id' => $request->collaborateur_id,
                    'assigned_by' => auth()->id(),
                    'assigned_at' => now(),
                ]);
                $created[] = $equip;
            }
        }

        return response()->json([
            'message' => count($created) . " équipement(s) créé(s) et attribué(s).",
            'equipments' => $created,
        ]);
    }
}
