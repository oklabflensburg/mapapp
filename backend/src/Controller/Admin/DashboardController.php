<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'app_admin')]
#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractDashboardController
{
    // Skip the bundle's welcome page; land admins in the first CRUD screen.
    public function index(): Response
    {
        return $this->redirect(
            $this->container->get(AdminUrlGenerator::class)
                ->setController(UserCrudController::class)
                ->generateUrl(),
        );
    }
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('AccessApp Admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Access', 'fa fa-lock');
        yield MenuItem::linkTo(UserCrudController::class, 'Users', 'fa fa-users');
        yield MenuItem::linkTo(UserRoleCrudController::class, 'User roles', 'fa fa-user-tag');

        yield MenuItem::section('User settings', 'fa fa-user-gear');
        yield MenuItem::linkTo(RoutePreferenceTagCrudController::class, 'Route preference tags', 'fa fa-sliders');

        yield MenuItem::section('Map data', 'fa fa-map');
        yield MenuItem::linkTo(NodeCrudController::class, 'Nodes', 'fa fa-location-dot');
        // Nodes and areas with unapproved tags share one review queue.
        yield MenuItem::linkToRoute('Pending observations', 'fa fa-clock', 'app_admin_pending_observations');
        yield MenuItem::linkTo(TagCrudController::class, 'Tags', 'fa fa-tags');
        yield MenuItem::linkTo(TagKeyLabelCrudController::class, 'Tag key labels', 'fa fa-language');
        yield MenuItem::linkTo(NodeTagCrudController::class, 'Node tags', 'fa fa-user-tag');
        yield MenuItem::linkTo(NodeTypeCrudController::class, 'Node types', 'fa fa-shapes');
        yield MenuItem::linkTo(NodeProviderCrudController::class, 'Node providers', 'fa fa-server');

        yield MenuItem::linkToLogout('Log out', 'fa fa-right-from-bracket');
    }
}
