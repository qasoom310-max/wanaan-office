<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Office\AgentModel;
use App\Office\Erp\ErpClient;
use App\Office\Spending;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(AgentModel $model, ErpClient $erp, Spending $spending, Team $team): View
    {
        $key = Setting::read(Setting::ANTHROPIC_API_KEY);

        return view('settings.edit', [
            'spending' => $spending,
            'team' => $team,
            'erpConnected' => $erp->isConnected(),
            'erpUrl' => Setting::read(ErpClient::URL),
            'erpWorkspaces' => implode(', ', $erp->workspaceIds()),
            'connected' => $model->isConfigured(),
            // Only the last four characters are ever shown back.
            'keyEnding' => $key !== null ? substr($key, -4) : null,
        ]);
    }

    /** The monthly AI budget: once it is used up, agents stop calling the AI. */
    public function budget(Request $request): RedirectResponse
    {
        $data = $request->validate(['budget' => ['required', 'numeric', 'min:1', 'max:100000']]);

        Setting::write(Spending::BUDGET, (string) round((float) $data['budget'], 2));

        return back()->with('status', 'Monthly budget set to $'.number_format((float) $data['budget'], 2).'.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['anthropic_api_key' => ['required', 'string', 'starts_with:sk-ant-', 'max:300']]);

        Setting::write(Setting::ANTHROPIC_API_KEY, trim($data['anthropic_api_key']));

        return back()->with('status', 'AI key saved. The agents can think now.');
    }
}
