<?php

namespace App\Controller\Admin;

use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use App\Entity\Tag;
use App\Service\AspectObservationApproval;
use App\Service\StreetPartAspects;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TagCrudController extends AbstractCrudController
{
    /**
     * The mapped (update-existing-element) approval's publish target for
     * the request being handled, created lazily by the factory below.
     */
    private ?Tag $mappedEffectiveTag = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagKeyLabeler $tagKeyLabeler,
        private readonly TagTranslationReview $translationReview,
        private readonly ObservationTranslations $observationTranslations,
        private readonly AspectObservationApproval $aspectObservationApproval,
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

        // A selected mapping target turns the approval into the admin's
        // update-vs-add decision; the empty/default option approves the
        // observation as submitted (new element).
        $targetPayload = $request->request->get('target_index');
        if (\is_string($targetPayload) && $targetPayload !== '') {
            return $this->approveMappedNodeGroupTag($request, $groupTag, $targetPayload);
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

    /**
     * Publishes a pending structured observation under an existing
     * element's identity instead of its proposed new-element key (see
     * specs/map-editing/aspect-observation-mapping/spec.md). Keeping the
     * proposed key is the add case handled above.
     */
    private function approveMappedNodeGroupTag(Request $request, NodeGroupTag $groupTag, string $targetPayload): Response
    {
        $proposedTag = $groupTag->getTag();
        $proposed = StreetPartAspects::parse($proposedTag->getKey());
        if ($proposed === null) {
            throw new BadRequestHttpException('Mapping targets apply to structured street-part observations only.');
        }
        $target = $this->aspectObservationApproval->decodeTarget($targetPayload);
        $effectiveKey = 'way.'.$proposed['type'].'.'.$target['index'].'.'.$proposed['aspect'];

        // The plain mapped post carries its CSRF token like a plain approve;
        // the translation form path validates its token while handling.
        if (!$request->request->has('translation_review_'.$groupTag->getId())) {
            if (!$this->isCsrfTokenValid('approve'.$groupTag->getId(), $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
        }

        // Stale mapping: the target element's approved value of this aspect
        // must still match the review screen's snapshot; 409 without any
        // change, so the decision is re-made against fresh data.
        if ($this->aspectObservationApproval->approvedAspectValue($groupTag->getGroup(), $effectiveKey) !== $target['aspectValue']) {
            throw new ConflictHttpException('The selected target element changed since the review page loaded. Reload and approve again.');
        }

        $this->mappedEffectiveTag = null;
        $effectiveTagFactory = function (string $value) use ($proposedTag, $effectiveKey): Tag {
            return $this->mappedEffectiveTag ??= $this->aspectObservationApproval->effectiveTag($proposedTag, $effectiveKey, $value);
        };

        // Translation review, CSRF and publication failures behave exactly
        // like a plain approval; publishing always targets the effective tag.
        if ($response = $this->reviewTranslations($request, $proposedTag, $groupTag->getId(), $effectiveTagFactory)) {
            return $response;
        }

        if ($this->mappedEffectiveTag === null) {
            throw new \LogicException('The mapped publish target was never created.');
        }
        $this->aspectObservationApproval->apply($groupTag, $this->mappedEffectiveTag);
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

    /**
     * Handles the translation review around an approval. With a non-null
     * $effectiveTagFactory (mapped update-of-existing approvals), approved
     * identities and translations publish under the effective key/value the
     * factory returns, never the proposed ones.
     *
     * @param ?\Closure(string $value): Tag $effectiveTagFactory
     */
    private function reviewTranslations(Request $request, Tag $tag, int $id, ?\Closure $effectiveTagFactory = null): ?Response
    {
        if (!$request->request->has('translation_review_'.$id)) {
            if (!$this->isCsrfTokenValid('approve'.$id, $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            if ($effectiveTagFactory === null) {
                $this->observationTranslations->publish($tag);

                return null;
            }
            $this->observationTranslations->publish($effectiveTagFactory($tag->getValue()));

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
                $publishIdentityMapped = $effectiveTagFactory !== null;
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
                        // A renamed identity has a different catalog target. Do not
                        // reuse the old identifier's conflict snapshot for that target.
                        $expected[$locale][$field] = ($identityChanged || $publishIdentityMapped) ? '' : ($data[$locale]['previous'.ucfirst($field)] ?? '');
                    }
                }
                if (!$form->get('approve')->isClicked()) {
                    $tag->setKey($data['key']);
                    $tag->setValue($data['value']);
                    $tag->setTranslations($translations);
                    $this->entityManager->flush();

                    return $this->redirectAfterApproval($request);
                }
                $publishTag = $tag;
                if ($publishIdentityMapped) {
                    $publishTag = $effectiveTagFactory($data['value']);
                    $publishTag->setTranslations($translations);
                } else {
                    $tag->setKey($data['key']);
                    $tag->setValue($data['value']);
                    $tag->setTranslations($translations);
                }
                try {
                    $this->observationTranslations->publish($publishTag, $expected);

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
