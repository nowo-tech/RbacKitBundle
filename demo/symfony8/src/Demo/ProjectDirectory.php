<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * The demo projects (an application reads them from its database).
 */
final class ProjectDirectory
{
    /**
     * @return array<string, DemoProject>
     */
    public function all(): array
    {
        return [
            'billing' => new DemoProject('billing', 'Billing'),
            'mobile'  => new DemoProject('mobile', 'Mobile app'),
        ];
    }

    public function find(string $slug): ?DemoProject
    {
        return $this->all()[$slug] ?? null;
    }
}
