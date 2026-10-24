<?php

namespace App\Controller\Admin;

use App\Entity\NodeTag;
use App\Entity\Tag;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
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
        yield BooleanField::new('approved');
        // Shows where the tag was added and by which user.
        yield AssociationField::new('nodeTags')
            ->hideOnForm()
            ->formatValue(static function ($nodeTags): string {
                $nodeTags = $nodeTags instanceof Collection ? $nodeTags->toArray() : [];

                return implode(', ', array_map('strval', $nodeTags));
            });
    }

    #[Route('/admin/tag/{id}/approve', name: 'app_admin_tag_approve', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approve(Request $request, int $id): Response
    {
        $tag = $this->entityManager->find(Tag::class, $id);

        if ($tag === null) {
            throw new NotFoundHttpException('Tag not found.');
        }

        if (!$this->isCsrfTokenValid('approve'.$id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tag->setApproved(true);
        $this->entityManager->flush();

        // Stay on the page the approval was triggered from (e.g. the node
        // edit view); only trust referers of this app instance.
        $referer = $request->headers->get('referer');
        if (\is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_admin_tag_index');
    }
}
