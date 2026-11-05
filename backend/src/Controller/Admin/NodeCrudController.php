<?php

namespace App\Controller\Admin;

use App\Entity\Node;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NodeCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Node::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Node')
            ->setEntityLabelInPlural('Nodes')
            ->setSearchFields(['providerNodeId'])
            // The edit page shows the edit form next to the app frontend
            // centered on the node (see templates/admin/node_edit.html.twig).
            ->overrideTemplate('crud/edit', 'admin/node_edit.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('nodeType');
        yield AssociationField::new('nodeProvider');
        yield TextField::new('providerNodeId');
        yield NumberField::new('latitude');
        yield NumberField::new('longitude');
        yield AssociationField::new('nodeTags');
        yield AssociationField::new('createdByUser');
        // Managed by the entity on persist; never editable.
        yield DateTimeField::new('createdAt')->onlyOnDetail();
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
    }

    #[Route('/admin/node/{id}/next-pending', name: 'app_admin_node_next_pending', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nextPending(Request $request, int $id): Response
    {
        // The next node with unapproved tags; after the last one, wrap
        // around to the first so the queue can be worked through in a loop.
        $nextId = $this->findPendingNodeId(afterId: $id) ?? $this->findPendingNodeId();

        if ($nextId === null) {
            $this->addFlash('warning', 'No pending observations.');

            // The queue page extends @EasyAdmin/layout.html.twig and only
            // renders with the EasyAdmin request context, which bare routes
            // do not carry; setRoute() routes through the admin route.
            return $this->redirect($this->container->get(AdminUrlGenerator::class)
                ->setRoute('app_admin_pending_observations')
                ->generateUrl());
        }

        // Stay in the section the button was clicked from; only trust
        // referers of this app instance.
        $controller = $this->refererIsNodeEdit($request) ? self::class : PendingObservationCrudController::class;

        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);

        return $this->redirect($adminUrlGenerator
            ->setController($controller)
            ->setAction(Action::EDIT)
            ->setEntityId($nextId)
            ->generateUrl());
    }

    private function findPendingNodeId(?int $afterId = null): ?int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('node.id')
            ->distinct()
            ->from(Node::class, 'node')
            ->innerJoin('node.nodeTags', 'nodeTag')
            ->where('nodeTag.approved = false')
            ->orderBy('node.id', 'ASC')
            ->setMaxResults(1);

        if ($afterId !== null) {
            $queryBuilder
                ->andWhere('node.id > :afterId')
                ->setParameter('afterId', $afterId);
        }

        /** @var array{id: int}|null $result */
        $result = $queryBuilder->getQuery()->getOneOrNullResult();

        return $result['id'] ?? null;
    }

    private function refererIsNodeEdit(Request $request): bool
    {
        $referer = $request->headers->get('referer');
        if (!\is_string($referer) || !str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return false;
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (!\is_string($path)) {
            return false;
        }

        return (bool) preg_match('#^/admin/node/\d+/edit$#', $path);
    }
}
