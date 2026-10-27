<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller\CustomObject;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\CustomObjectsBundle\Exception\DefinitionImportException;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Form\Type\CustomObjectDefinitionImportType;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImportAlerts;
use MauticPlugin\CustomObjectsBundle\Helper\CustomObjectDefinitionImporter;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectPermissionProvider;
use MauticPlugin\CustomObjectsBundle\Provider\CustomObjectRouteProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Import of the definition of a Custom Object (the JSON of the Clone tab of another instance): a page with a text area, and the POST that
 * checks first (what it would do, nothing saved) and, once confirmed, creates the object or adds to an existing one the fields it does not have. Only for who may create objects (and edit the object that receives fields).
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
        CustomObjectDefinitionImporter $importer,
        CustomObjectDefinitionImportAlerts $alerts
    ): Response {
        try {
            $permissionProvider->canCreate();
        } catch (ForbiddenException $e) {
            return $this->accessDenied(false, $e->getMessage());
        }

        $request = $this->getCurrentRequest();
        $form    = $this->createImportForm($formFactory, $routeProvider);
        $form->handleRequest($request);

        $json      = (string) ($form->get('definition')->getData() ?? '');
        $confirmed = '1' === (string) $form->get('confirmed')->getData();

        // first the check: what the import WOULD do, nothing saved; the person confirms it on the next submit
        if (!$confirmed) {
            try {
                $check = $importer->import($json, false);
            } catch (DefinitionImportException $e) {
                return $this->renderImportForm($this->createImportForm($formFactory, $routeProvider, false, $json), $routeProvider, $e->getErrors());
            }

            return $this->renderImportForm(
                $this->createImportForm($formFactory, $routeProvider, true, $json),
                $routeProvider,
                [],
                $alerts->fromResult($check, true)
            );
        }

        try {
            $result = $importer->import($json);
        } catch (DefinitionImportException $e) {
            return $this->renderImportForm($this->createImportForm($formFactory, $routeProvider), $routeProvider, $e->getErrors(), [], $json);
        }

        // the object page shows what was done as alerts (it reads them once from the session)
        $request->getSession()->getFlashBag()->set(CustomObjectDefinitionImportAlerts::SESSION_KEY, $alerts->fromResult($result));
        $url = $routeProvider->buildViewRoute((int) $result->getCustomObject()->getId());

        return $request->isXmlHttpRequest() ? new JsonResponse(['redirect' => $url]) : $this->redirect($url);
    }

    private function createImportForm(
        FormFactoryInterface $formFactory,
        CustomObjectRouteProvider $routeProvider,
        bool $confirming = false,
        string $json = ''
    ): FormInterface {
        $cancel = $routeProvider->buildCancelRoute();

        return $formFactory->create(CustomObjectDefinitionImportType::class, ['definition' => $json], [
            'action'         => $routeProvider->buildImportDefinitionSaveRoute(),
            'cancel_onclick' => "mQuery('form[name=custom_object_definition_import]').attr('action', '".$cancel."');",
            'confirming'     => $confirming,
        ]);
    }

    /**
     * @param string[]                                                      $errors
     * @param array<int, array{type: string, message: string, items: string[]}> $checkAlerts what the import would do (the check step)
     */
    private function renderImportForm(
        FormInterface $form,
        CustomObjectRouteProvider $routeProvider,
        array $errors,
        array $checkAlerts = [],
        string $json = ''
    ): Response {
        return $this->delegateView([
            'returnUrl'      => $routeProvider->buildImportDefinitionRoute(),
            'viewParameters' => [
                'form'        => $form->createView(),
                'errors'      => $errors,
                'checkAlerts' => $checkAlerts,
            ],
            'contentTemplate' => '@CustomObjects/CustomObject/import_definition.html.twig',
            'passthroughVars' => [
                'mauticContent' => 'customObject',
                'route'         => $routeProvider->buildImportDefinitionRoute(),
            ],
        ]);
    }
}
