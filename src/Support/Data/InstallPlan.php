<?php

namespace ServerSuite\Support\Data;

/**
 * The full outcome of planning an install: every mod the planner touched
 * (the requested mod plus its resolved dependency tree), each tagged with a
 * status. The UI shows this and asks the user to confirm before execution.
 */
readonly class InstallPlan
{
    /**
     * @param InstallPlanItem[] $items
     */
    public function __construct(
        public array $items,
    ) {}

    /** Items that will actually be downloaded and installed. */
    public function toInstall(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i->status === 'install'));
    }

    /** Items already present at the requested version. */
    public function satisfied(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i->status === 'satisfied'));
    }

    /** Items installed at a different version — need a user decision. */
    public function conflicts(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i->status === 'conflict'));
    }

    /** Items that cannot be auto-installed (no download URL). */
    public function manual(): array
    {
        return array_values(array_filter($this->items, fn ($i) => $i->status === 'manual'));
    }

    /** Whether anything in the plan needs the user's attention before executing. */
    public function needsConfirmation(): bool
    {
        return count($this->conflicts()) > 0
            || count($this->manual()) > 0
            || count($this->dependencyInstalls()) > 0;
    }

    /** Dependency items (not the primary mod) that will be installed. */
    public function dependencyInstalls(): array
    {
        return array_values(array_filter(
            $this->toInstall(),
            fn ($i) => $i->kind === 'dependency'
        ));
    }

    public function toArray(): array
    {
        return [
            'items'              => array_map(fn (InstallPlanItem $i) => $i->toArray(), $this->items),
            'summary'            => [
                'install'            => count($this->toInstall()),
                'satisfied'          => count($this->satisfied()),
                'conflicts'          => count($this->conflicts()),
                'manual'             => count($this->manual()),
                'dependency_installs'=> count($this->dependencyInstalls()),
                'needs_confirmation' => $this->needsConfirmation(),
            ],
        ];
    }
}
