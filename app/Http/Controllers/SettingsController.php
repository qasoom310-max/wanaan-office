<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Office\AgentModel;
use App\Office\Erp\ErpClient;
use App\Office\Erp\ErpProblem;
use App\Office\Seo\SeoClient;
use App\Office\Spending;
use App\Office\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(AgentModel $model, ErpClient $erp, SeoClient $seo, Spending $spending, Team $team): View
    {
        $key = Setting::read(Setting::ANTHROPIC_API_KEY);

        return view('settings.edit', [
            'spending' => $spending,
            'team' => $team,
            'erpConnected' => $erp->isConnected(),
            'erpUrl' => Setting::read(ErpClient::URL),
            'erpWorkspaces' => implode(', ', $erp->workspaceIds()),
            'seoConnected' => $seo->isConnected(),
            'seoUrl' => Setting::read(SeoClient::URL),
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

    /**
     * Connect the ERP: the address and a read-only key the owner created in the ERP under
     * Settings → AI office. The ERP itself says which companies the key may read.
     */
    public function erp(Request $request, ErpClient $erp): RedirectResponse
    {
        $data = $request->validate([
            'erp_url' => ['required', 'url:https,http', 'max:200'],
            'erp_key' => ['required', 'string', 'starts_with:office_', 'max:200'],
        ], ['erp_key.starts_with' => 'That is not an ERP office key: it starts with office_.']);

        try {
            $companies = $erp->companiesFor(rtrim($data['erp_url'], '/'), trim($data['erp_key']));
        } catch (ErpProblem $problem) {
            return back()->withErrors(['erp_key' => ucfirst($problem->getMessage()).'.'])->withInput($request->except('erp_key'));
        }

        if ($companies === []) {
            return back()->withErrors(['erp_key' => 'The key may not read any company. Tick at least one when creating it.'])->withInput($request->except('erp_key'));
        }

        Setting::write(ErpClient::URL, rtrim($data['erp_url'], '/'));
        Setting::write(ErpClient::KEY, trim($data['erp_key']));
        Setting::write(ErpClient::WORKSPACES, implode(',', array_column($companies, 'id')));

        return back()->with('status', 'Connected to the ERP: '.implode(', ', array_column($companies, 'name')).'.');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['anthropic_api_key' => ['required', 'string', 'starts_with:sk-ant-', 'max:300']]);

        Setting::write(Setting::ANTHROPIC_API_KEY, trim($data['anthropic_api_key']));

        return back()->with('status', 'AI key saved. The agents can think now.');
    }
}
