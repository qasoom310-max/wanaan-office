<?php

namespace App\Office\Tools;

use Closure;

/**
 * Something an agent can do while answering, such as reading the ERP. `step` turns the
 * agent's request into the line its desk shows ("Opening the ERP: journal entries").
 */
class AgentTool
{
    /**
     * @param  array<string, mixed>  $schema  JSON schema of the input
     * @param  Closure(array<string, mixed>): string  $step
     * @param  Closure(array<string, mixed>): string  $run  returns what the agent reads back
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
        public Closure $step,
        public Closure $run,
    ) {}

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>}
     */
    public function definition(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'inputSchema' => $this->schema];
    }
}
