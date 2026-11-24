<?php

namespace App\Dashboard;

use App\Entity\User;
use App\Entity\UserPreference;
use App\Enum\DashboardRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Loads and saves the current user's dashboard role (All / Business / Dev) and enabled widgets.
 */
final class DashboardPreferences
{
    /**
     * @var array<string, array{group: 'business'|'dev'|'shared', label: string, description: string}>
     */
    public const WIDGETS = [
        'business_overview' => ['group' => 'business', 'label' => 'dashboard.widget.business_overview', 'description' => 'dashboard.widget.business_overview_desc'],
        'shadow_questions' => ['group' => 'business', 'label' => 'dashboard.widget.shadow_questions', 'description' => 'dashboard.widget.shadow_questions_desc'],
        'recent_documents' => ['group' => 'business', 'label' => 'dashboard.widget.recent_documents', 'description' => 'dashboard.widget.recent_documents_desc'],
        'activity_chart' => ['group' => 'dev', 'label' => 'dashboard.widget.activity_chart', 'description' => 'dashboard.widget.activity_chart_desc'],
        'memories_chart' => ['group' => 'dev', 'label' => 'dashboard.widget.memories_chart', 'description' => 'dashboard.widget.memories_chart_desc'],
        'memory_types' => ['group' => 'dev', 'label' => 'dashboard.widget.memory_types', 'description' => 'dashboard.widget.memory_types_desc'],
        'mcp_tools' => ['group' => 'dev', 'label' => 'dashboard.widget.mcp_tools', 'description' => 'dashboard.widget.mcp_tools_desc'],
        'search_breakdown' => ['group' => 'dev', 'label' => 'dashboard.widget.search_breakdown', 'description' => 'dashboard.widget.search_breakdown_desc'],
        'recent_calls' => ['group' => 'dev', 'label' => 'dashboard.widget.recent_calls', 'description' => 'dashboard.widget.recent_calls_desc'],
        'attention' => ['group' => 'shared', 'label' => 'dashboard.widget.attention', 'description' => 'dashboard.widget.attention_desc'],
        'ai_quality' => ['group' => 'shared', 'label' => 'dashboard.widget.ai_quality', 'description' => 'dashboard.widget.ai_quality_desc'],
        'timeline' => ['group' => 'shared', 'label' => 'dashboard.widget.timeline', 'description' => 'dashboard.widget.timeline_desc'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_DEFAULTS = [
        DashboardRole::All->value => [
            'business_overview',
            'shadow_questions',
            'activity_chart',
            'memories_chart',
            'memory_types',
            'mcp_tools',
            'search_breakdown',
            'recent_calls',
            'attention',
            'ai_quality',
            'timeline',
        ],
        DashboardRole::Business->value => [
            'business_overview',
            'shadow_questions',
            'recent_documents',
            'memories_chart',
            'attention',
            'ai_quality',
            'timeline',
        ],
        DashboardRole::Dev->value => [
            'activity_chart',
            'memories_chart',
            'memory_types',
            'mcp_tools',
            'search_breakdown',
            'recent_calls',
            'attention',
            'ai_quality',
            'timeline',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{role: DashboardRole, widgets: list<string>, custom: bool}
     */
    public function current(): array
    {
        $pref = $this->find();
        $role = $pref?->getDashboardRole() ?? DashboardRole::All;
        $custom = $pref?->getDashboardWidgets();

        return [
            'role' => $role,
            'widgets' => null !== $custom ? $this->sanitize($custom) : self::defaultsFor($role),
            'custom' => null !== $custom,
        ];
    }

    /**
     * Switches to a role preset and resets custom widget overrides to that role's defaults.
     */
    public function selectRole(DashboardRole $role): void
    {
        $pref = $this->findOrCreate();
        $pref->setDashboardRole($role);
        $pref->setDashboardWidgets(null);
        $this->entityManager->flush();
    }

    /**
     * Saves a custom list of enabled widgets for the given role.
     *
     * @param list<string> $widgets
     */
    public function saveCustom(DashboardRole $role, array $widgets): void
    {
        $pref = $this->findOrCreate();
        $pref->setDashboardRole($role);
        $sanitized = $this->sanitize($widgets);
        $pref->setDashboardWidgets($sanitized === self::defaultsFor($role) ? null : $sanitized);
        $this->entityManager->flush();
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(DashboardRole $role): array
    {
        return self::ROLE_DEFAULTS[$role->value];
    }

    private function ownerKey(): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User && null !== $user->getId()) {
            return 'user:'.$user->getId();
        }
        if (null !== $user) {
            return 'account:'.substr($user->getUserIdentifier(), 0, 55);
        }

        return 'local';
    }

    private function find(): ?UserPreference
    {
        return $this->entityManager->getRepository(UserPreference::class)->findOneBy(['ownerKey' => $this->ownerKey()]);
    }

    private function findOrCreate(): UserPreference
    {
        $pref = $this->find();
        if (null === $pref) {
            $pref = new UserPreference($this->ownerKey());
            $this->entityManager->persist($pref);
        }

        return $pref;
    }

    /**
     * @param list<string> $widgets
     *
     * @return list<string>
     */
    private function sanitize(array $widgets): array
    {
        $allowed = array_keys(self::WIDGETS);

        return array_values(array_intersect($allowed, $widgets));
    }
}
