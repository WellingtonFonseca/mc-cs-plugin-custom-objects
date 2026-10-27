<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomObject;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Form\Type\CustomObjectDefinitionImportType;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import of the definition of a Custom Object (the JSON of the Clone tab of another instance): a page with a text area, and the POST that
 * creates the object, or adds to an existing one the fields it does not have. Only for who may create objects (and edit the object that receives fields).
 */
class ImportDefinitionController extends CommonController
{
    public function formAction(
        FormFactoryInterface $formFactory,
        CustomObjectPermissionProvider $permissionProvider,
        CustomObjectRouteProvider $routeProvider
    ): Response {
        try {
            $permissionProvider->canCreate();
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        return $this->renderImportForm($this->createImportForm($formFactory, $routeProvider), $routeProvider, []);
    }

    public function saveAction(
        FormFactoryInterface $formFactory,
        CustomObjectPermissionProvider $permissionProvider,
        CustomObjectRouteProvider $routeProvider,
        CustomObjectDefinitionImporter $importer
    ): Response {
        try {
            $permissionProvider->canCreate();
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        $request = $this->getCurrentRequest();
        $form    = $this->createImportForm($formFactory, $routeProvider);
        $form->handleRequest($request);

        $json = (string) ($form->get('definition')->getData() ?? '');
        try {
            $result = $importer->import($json);
        } catch (DefinitionImportException $e) {
            return $this->renderImportForm($form, $routeProvider, $e->getErrors());
        }

        $customObject = $result->getCustomObject();
        $name         = (string) $customObject->getName();
        if ($result->isCreated()) {
            $this->addFlashMessage('custom.object.import.done', ['%name%' => $name]);
        } elseif ([] === $result->getAddedAliases()) {
            $this->addFlashMessage('custom.object.import.nothing', ['%name%' => $name]);
        } else {
            $this->addFlashMessage('custom.object.import.added', ['%name%' => $name, '%fields%' => implode(', ', $result->getAddedAliases())]);
        }
        if (!$result->isCreated() && [] !== $result->getSkipped()) {
            $this->addFlashMessage('custom.object.import.skipped', ['%fields%' => implode(', ', $result->getSkipped())]);
        }
        $url = $routeProvider->buildViewRoute((int) $customObject->getId());

        return $request->isXmlHttpRequest() ? new JsonResponse(['redirect' => $url]) : $this->redirect($url);
    }

    private function createImportForm(FormFactoryInterface $formFactory, CustomObjectRouteProvider $routeProvider): FormInterface
    {
        $cancel = $routeProvider->buildCancelRoute();

        return $formFactory->create(CustomObjectDefinitionImportType::class, null, [
            'action'         => $routeProvider->buildImportDefinitionSaveRoute(),
            'cancel_onclick' => "mQuery('form[name=custom_object_definition_import]').attr('action', '".$cancel."');",
        ]);
    }

    /**
     * @param string[] $errors
     */
    private function renderImportForm(FormInterface $form, CustomObjectRouteProvider $routeProvider, array $errors): Response
    {
        return $this->delegateView([
            'returnUrl'      => $routeProvider->buildImportDefinitionRoute(),
            'viewParameters' => [
                'form'   => $form->createView(),
                'errors' => $errors,
            ],
            'contentTemplate' => '@CustomObjects/CustomObject/import_definition.html.twig',
            'passthroughVars' => [
                'mauticContent' => 'customObject',
                'route'         => $routeProvider->buildImportDefinitionRoute(),
            ],
        ]);
    }
}
