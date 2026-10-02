<?php

namespace Modules\MatterlyConflicts;

use App\Models\Client;
use App\Support\ModuleNav;
use Modules\MatterlyConflicts\Models\Check;
use Modules\MatterlyConflicts\Support\ConflictSearch;
use Pacific\Licentra\Modules\Laravel\ModuleServiceProvider as BaseProvider;

class ModuleServiceProvider extends BaseProvider
{
    public function bootModule(): void
    {
        $this->loadModuleResources();
        $this->loadModuleRoutes('routes/web.php', ['web']);

        ModuleNav::firm('matterly-conflicts', __('matterly-conflicts::messages.title'), '/matterly-conflicts', icon: 'shield', permission: 'clients');
        ModuleNav::superAdmin('matterly-conflicts', __('matterly-conflicts::messages.title'), '/matterly-conflicts/admin', icon: 'shield');

        // A new client who was once on the other side of one of the firm's matters is flagged for review.
        // If this fails the client is still saved (observe() guards it).
        $this->observe(Client::class, 'created', function (Client $client) {
            $name = trim($client->first_name.' '.$client->last_name);
            if ($name === '' || ! $client->firm_id) {
                return;
            }
            $result = (new ConflictSearch((int) $client->firm_id))->run($name, exceptClientId: $client->id);
            if ($result['adverse'] === 0) {
                return;
            }
            Check::withoutGlobalScope('firm')->create([
                'firm_id' => $client->firm_id,
                'client_id' => $client->id,
                'user_id' => auth()->id(),
                'query' => mb_substr($name, 0, 160),
                'outcome' => 'pending',
                'source' => 'auto',
                'hits' => ConflictSearch::snapshot($result),
                'hit_count' => $result['count'],
            ]);
        });
    }

    public function filamentPlugins(string $panel): array
    {
        return [];
    }
}
