<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommentaireTache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentaireTacheController extends Controller
{
    /**
     * GET /api/v1/commentaires-taches?tache_id=X
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tache_id' => 'required|exists:taches,id',
        ]);

        $commentaires = CommentaireTache::with('user:id,prenom,nom')
            ->where('tache_id', $data['tache_id'])
            ->orderBy('created_at')
            ->get();

        return response()->json($commentaires);
    }

    /**
     * POST /api/v1/commentaires-taches
     * L'auteur (user_id) est résolu depuis l'utilisateur authentifié.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tache_id' => 'required|exists:taches,id',
            'contenu' => 'required|string|max:5000',
        ]);

        $data['user_id'] = Auth::id();

        $commentaire = CommentaireTache::create($data);
        $commentaire->load('user:id,prenom,nom');

        return response()->json($commentaire, 201);
    }

    /**
     * PUT /api/v1/commentaires-taches/{commentaire}
     * Seul l'auteur peut modifier son commentaire.
     */
    public function update(Request $request, CommentaireTache $commentaire): JsonResponse
    {
        if ($commentaire->user_id !== Auth::id()) {
            return response()->json(['error' => 'Vous ne pouvez modifier que vos propres commentaires'], 403);
        }

        $data = $request->validate([
            'contenu' => 'required|string|max:5000',
        ]);

        $commentaire->update($data);
        $commentaire->load('user:id,prenom,nom');

        return response()->json($commentaire);
    }

    /**
     * DELETE /api/v1/commentaires-taches/{commentaire}
     * L'auteur peut supprimer ses commentaires. (Les admins peuvent aussi via le middleware projets,admin.)
     */
    public function destroy(CommentaireTache $commentaire): JsonResponse
    {
        if ($commentaire->user_id !== Auth::id()) {
            // TODO [RBAC integration]: vérifier ici si l'utilisateur a permission projets,admin
            // pour autoriser la suppression de commentaires d'autrui
            return response()->json(['error' => 'Vous ne pouvez supprimer que vos propres commentaires'], 403);
        }

        $commentaire->delete();

        return response()->json(null, 204);
    }
}
