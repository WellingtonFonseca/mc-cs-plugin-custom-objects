<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Controller;

use Mautic\CoreBundle\Helper\InputHelper;
use MauticPlugin\CustomObjectsBundle\Exception\ForbiddenException;
use MauticPlugin\CustomObjectsBundle\Exception\NotFoundException;
use MauticPlugin\CustomObjectsBundle\Helper\SegmentFilterValues;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /s/custom/segment-filter-values.json?field=cmf_14&q=abc&limit=20: the suggestions of the segment form's chips
 * for a Custom Object text filter (see SegmentFilterValues).
 */
class SegmentFilterValuesController extends AbstractController
{
    public function valuesAction(Request $request, SegmentFilterValues $values): JsonResponse
    {
        try {
            return new JsonResponse(['values' => $values->find(
                InputHelper::alphanum((string) $request->query->get('field'), false, null, ['_']),
                InputHelper::clean((string) $request->query->get('q')),
                (int) $request->query->get('limit', 20)
            )]);
        } catch (ForbiddenException) {
            return new JsonResponse(['values' => []], 403);
        } catch (NotFoundException) {
            return new JsonResponse(['values' => []], 404);
        }
    }
}
