<?php

namespace App\Controller\Admin;

use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use App\Entity\Tag;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TagCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }
    public static function getEntityFqcn(): string
    {
        return Tag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tag')
            ->setEntityLabelInPlural('Tags')
            ->setSearchFields(['key', 'value']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key');
        yield TextField::new('value');
        // Shows where the tag was added and by which user.
        yield AssociationField::new('nodeTags')
            ->hideOnForm()
            ->formatValue(static function ($nodeTags): string {
                $nodeTags = $nodeTags instanceof Collection ? $nodeTags->toArray() : [];

                return implode(', ', array_map('strval', $nodeTags));
            });
    }

    #[Route('/admin/node-tag/{id}/approve', name: 'app_admin_node_tag_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approveNodeTag(Request $request, int $id): Response
    {
        $nodeTag = $this->entityManager->find(NodeTag::class, $id);

        if ($nodeTag === null) {
            throw new NotFoundHttpException('Node tag not found.');
        }

        if (!$this->isCsrfTokenValid('approve'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Approving the value on this node approves every user's observation
        // of it there; the same value on other nodes or relations keeps its
        // own pending state.
        $nodeTag->setApproved(true);
        foreach ($nodeTag->getNode()->getNodeTags() as $other) {
            if ($other->getTag() === $nodeTag->getTag()) {
                $other->setApproved(true);
            }
        }
        $this->entityManager->flush();

        return $this->redirectAfterApproval($request);
    }

    #[Route('/admin/node-group-tag/{id}/approve', name: 'app_admin_node_group_tag_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approveNodeGroupTag(Request $request, int $id): Response
    {
        $groupTag = $this->entityManager->find(NodeGroupTag::class, $id);

        if ($groupTag === null) {
            throw new NotFoundHttpException('Node group tag not found.');
        }

        if (!$this->isCsrfTokenValid('approve'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Approving the value on this relation approves every user's
        // observation of it there; everywhere else stays pending.
        $groupTag->setApproved(true);
        foreach ($groupTag->getGroup()->getGroupTags() as $other) {
            if ($other->getTag() === $groupTag->getTag()) {
                $other->setApproved(true);
            }
        }
        $this->entityManager->flush();

        return $this->redirectAfterApproval($request);
    }

    /**
     * Stay on the page the approval was triggered from (e.g. a review
     * screen); only trust referers of this app instance.
     */
    private function redirectAfterApproval(Request $request): Response
    {
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_admin_pending_observations');
    }
}
