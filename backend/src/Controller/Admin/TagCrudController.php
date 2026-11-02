<?php

namespace App\Controller\Admin;

use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use App\Entity\Tag;
use App\Service\TagKeyLabeler;
use App\Service\TagTranslationReview;
use App\Service\ObservationTranslations;
use Symfony\Component\Form\FormError;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TagCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagKeyLabeler $tagKeyLabeler,
        private readonly TagTranslationReview $translationReview,
        private readonly ObservationTranslations $observationTranslations,
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
        // The list shows the translated label, the form keeps the raw key
        // because that is what is stored and searched.
        yield TextField::new('key')
            ->formatValue(fn ($value) => $this->tagKeyLabeler->label((string) $value));
        yield TextField::new('value')
            ->formatValue(fn ($value, Tag $tag) => $this->tagKeyLabeler->valueLabel($tag->getKey(), (string) $value));
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

        if ($response = $this->reviewTranslations($request, $nodeTag->getTag(), $id)) {
            return $response;
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

        if ($response = $this->reviewTranslations($request, $groupTag->getTag(), $id)) {
            return $response;
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

    #[Route('/admin/node-tag/{id}/decline', name: 'app_admin_node_tag_decline', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function declineNodeTag(Request $request, int $id): Response
    {
        $entry = $this->entityManager->find(NodeTag::class, $id);
        if ($entry === null) {
            throw $this->createNotFoundException('Node tag not found.');
        }

        return $this->declineTag($request, $entry, 'decline_node_tag'.$id);
    }

    #[Route('/admin/node-group-tag/{id}/decline', name: 'app_admin_node_group_tag_decline', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function declineNodeGroupTag(Request $request, int $id): Response
    {
        $entry = $this->entityManager->find(NodeGroupTag::class, $id);
        if ($entry === null) {
            throw $this->createNotFoundException('Node group tag not found.');
        }

        return $this->declineTag($request, $entry, 'decline_node_group_tag'.$id);
    }

    private function declineTag(Request $request, NodeTag|NodeGroupTag $entry, string $tokenId): Response
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($entry->isApproved()) {
            throw new ConflictHttpException('This observation has already been approved.');
        }
        $entries = $entry instanceof NodeTag ? $entry->getNode()->getNodeTags() : $entry->getGroup()->getGroupTags();
        // The UI groups identical observations from multiple authors. Decline
        // that pending value here, preserving its uses on other nodes/areas.
        foreach ($entries->toArray() as $other) {
            if (!$other->isApproved() && $other->getTag() === $entry->getTag()) {
                $entries->removeElement($other);
                $this->entityManager->remove($other);
            }
        }
        $this->entityManager->flush();

        return $this->redirectAfterApproval($request);
    }

    private function reviewTranslations(Request $request, Tag $tag, int $id): ?Response
    {
        if (!$request->request->has('translation_review_'.$id)) {
            if (!$this->isCsrfTokenValid('approve'.$id, $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            $this->observationTranslations->publish($tag);

            return null;
        }

        $form = $this->translationReview->form($tag, $id, $request->getPathInfo());
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!hash_equals($this->translationReview->version($tag), $data['version'] ?? '')) {
                $form->addError(new FormError('The tag or proposed translations changed. Copy your draft and reload before saving again.'));
            } else {
                $keyChanged = $tag->getKey() !== $data['key'];
                $valueChanged = $tag->getValue() !== $data['value'];
                $translations = $tag->getTranslations();
                $expected = [];
                foreach (TagKeyLabeler::SUPPORTED_LOCALES as $locale) {
                    foreach (['keyTranslation', 'valueTranslation'] as $field) {
                        $identityChanged = $keyChanged || ($field === 'valueTranslation' && $valueChanged);
                        // Keep displayed labels when their raw identifier changes, but
                        // leave untouched languages without any translation alone.
                        if (!array_key_exists($field, $translations[$locale] ?? []) && $data[$locale][$field] === ($data[$locale]['previous'.ucfirst($field)] ?? '') && (!$identityChanged || $data[$locale][$field] === '')) {
                            continue;
                        }
                        $translations[$locale][$field] = $data[$locale][$field];
                        // A renamed identifier has a different catalog target. Do not
                        // reuse the old identifier's conflict snapshot for that target.
                        $expected[$locale][$field] = $identityChanged ? '' : ($data[$locale]['previous'.ucfirst($field)] ?? '');
                    }
                }
                $tag->setKey($data['key']);
                $tag->setValue($data['value']);
                $tag->setTranslations($translations);
                if (!$form->get('approve')->isClicked()) {
                    $this->entityManager->flush();

                    return $this->redirectAfterApproval($request);
                }
                try {
                    $this->observationTranslations->publish($tag, $expected);

                    return null;
                } catch (\DomainException|\OutOfBoundsException|\InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));
                } catch (\RuntimeException $exception) {
                    $form->addError(new FormError('Could not publish translations. The observation has not been approved.'));
                }
            }
        }

        return $this->render('admin/translation_review_error.html.twig', ['form' => $form->createView(), 'tag' => $tag], new Response(status: 422));
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

        // The queue page extends @EasyAdmin/layout.html.twig and only renders
        // with the EasyAdmin request context, which bare routes do not carry;
        // setRoute() routes through the admin route.
        return $this->redirect($this->container->get(AdminUrlGenerator::class)
            ->setRoute('app_admin_pending_observations')
            ->generateUrl());
    }
}
