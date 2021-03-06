<?php

namespace Ob\CmsBundle\Controller;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Templating\EngineInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Ob\CmsBundle\Admin\AdminContainer;
use Ob\CmsBundle\Datagrid\DatagridInterface;
use Ob\CmsBundle\Export\ExporterInterface;
use Ob\CmsBundle\Form\AdminType;

class AdminController
{
    private $templating;
    private $entityManager;
    private $formFactory;
    private $router;
    private $session;
    private $container;
    private $datagrid;
    private $templates;
    private $exporter;
    
    public function __construct(
        EngineInterface $templating,
        ObjectManager $entityManager,
        FormFactoryInterface $formFactory,
        RouterInterface $router,
        $session,
        AdminContainer $container,
        DatagridInterface $datagrid,
        $templates,
        ExporterInterface $exporter
    )
    {
        $this->templating = $templating;
        $this->entityManager = $entityManager;
        $this->formFactory = $formFactory;
        $this->router = $router;
        $this->session = $session;
        $this->container = $container;
        $this->datagrid = $datagrid;
        $this->templates = $templates;
        $this->exporter = $exporter;
    }

    /**
     * Render the menu
     *
     * @param $request
     *
     * @return Response
     */
    public function menuAction(Request $request)
    {
        $menu = $this->container->getClasses();

        // Get the current module from the URI
        $current = explode('?', $request->server->get('REQUEST_URI'));
        $current = $current[0];

        return $this->templating->renderResponse($this->templates['menu'], array(
            'items'   => $menu,
            'flat'    => true,
            'current' => $current,
        ));
    }

    /**
     * Display the homepage/dashboard
     *
     * @return Response
     */
    public function dashboardAction()
    {
        return $this->templating->renderResponse($this->templates['dashboard']);
    }

    /**
     * Display the listing page.
     * Handles searches, sorting, actions and pagination on the list of entities.
     *
     * @param Request $request
     * @param string  $name
     *
     * @return Response
     */
    public function listAction(Request $request, $name)
    {
        $this->executeAction($request, $name);

        $adminClass = $this->container->getClass($name);
        $entities = $this->datagrid->getPaginatedEntities($adminClass);
        $template = $adminClass->listTemplate() ? : $this->templates['list'];
        $filters = $this->datagrid->getFilters($adminClass);

        return $this->templating->renderResponse($template, array(
            'module'     => $name,
            'adminClass' => $adminClass,
            'entities'   => $entities,
            'search'     => $request->query->get('search') ? : null,
            'filters'    => $filters,
            'selectedFilters' => $request->query->get('filter')
        ));
    }

    /**
     * Export the listing
     *
     * @param Request $request
     * @param string  $name
     * @param string  $format
     *
     * @return Response
     */
    public function exportAction(Request $request, $name, $format)
    {
        $adminClass = $this->container->getClass($name);
        $entities = $this->datagrid->getEntities($adminClass);

        $now = new \DateTime();
        $filename = $now->format('Y-m-d-') . $name . '.' . $format;

        return $this->exporter->export($filename, $format, $entities, $adminClass->listExport());
    }

    /**
     * Display the form to create a new entity
     *
     * @param Request $request
     * @param string  $name
     *
     * @return Response|RedirectResponse
     */
    public function newAction(Request $request, $name)
    {
        $adminClass = $this->container->getClass($name);
        $entity = $adminClass->getClass();
        $entity = new $entity;

        $formType = $adminClass->formType();
        $formType = $formType ? new $formType() : new AdminType($adminClass->formDisplay());
        $form = $this->formFactory->create($formType, $entity);
        $form = $this->addRefererField($request, $form);

        if ($request->isMethod('POST')) {
            if ($form->submit($request)->isValid()) {
                $this->entityManager->persist($entity);
                $this->entityManager->flush();
                $this->session->getFlashBag()->add('success', $name . '.create.success');

                return new RedirectResponse($this->router->generate('ObCmsBundle_module_edit', array(
                    'name' => $name,
                    'id' => $entity->getId(),
                    'referer' => $this->getReferer($request, $form)
                )));
            }
        }

        $template = $adminClass->newTemplate() ? : $this->templates['new'];

        return $this->templating->renderResponse($template, array(
            'module' => $name,
            'entity' => $entity,
            'form'   => $form->createView(),
            'referer' => $this->getReferer($request, $form)
        ));
    }

    /**
     * Display the form to edit an entity
     *
     * @param Request $request
     * @param string  $name
     * @param int     $id
     *
     * @return Response
     *
     * @throws NotFoundHttpException
     */
    public function editAction(Request $request, $name, $id)
    {
        $adminClass = $this->container->getClass($name);
        $entity = $this->entityManager->getRepository($adminClass->getRepository())->find($id);

        if (!$entity) {
            throw $this->createNotFoundException('Unable to find ' . $name . ' entity.');
        }

        $formType = $adminClass->formType();
        $formType = $formType ? new $formType() : new AdminType($adminClass->formDisplay());
        $editForm = $this->formFactory->create($formType, $entity);
        $editForm = $this->addRefererField($request, $editForm);

        if ($request->isMethod('POST')) {
            if ($editForm->submit($request)->isValid()) {
                $this->entityManager->persist($entity);
                $this->entityManager->flush();
                $this->session->getFlashBag()->add('success', $name . '.edit.success');
            }
        }

        $template = $adminClass->editTemplate() ? : $this->templates['edit'];

        return $this->templating->renderResponse($template, array(
            'module' => $name,
            'entity' => $entity,
            'form' => $editForm->createView(),
            'referer' => $this->getReferer($request, $editForm)
        ));
    }

    /**
     * @param Request       $request
     * @param FormInterface $form
     *
     * @return FormInterface
     */
    private function addRefererField($request, $form)
    {
        $referer = $this->getReferer($request, $form);

        $form->add('referer', 'hidden', array('mapped' => false));
        $form->get('referer')->setData($referer);

        return $form;
    }

    /**
     * @param Request       $request
     * @param FormInterface $form
     *
     * @return string
     */
    private function getReferer($request, $form)
    {
        if ($form->has('referer')) {
            $referer = $form->get('referer')->getData();
        } elseif ($request->query->has('referer')) {
            $referer = $request->query->get('referer');
        } else {
            $referer = $request->headers->get("referer");
        }

        return $referer;
    }

    /**
     * Executes an action on selected table rows
     *
     * @param Request $request
     * @param string  $name
     */
    private function executeAction(Request $request, $name)
    {
        if ($request->getMethod() == 'POST') {
            $action = $request->get('action');
            $ids = $request->get('action-checkbox')?:array();
            $ids = array_keys($ids);

            if (!empty($ids) and $action != '') {
                $adminClass = $this->container->getClass($name);
                $entities = $this->entityManager->getRepository($adminClass->getRepository())->findById($ids);

                foreach ($entities as $entity) {
                    // TODO: check if function exists or raise Exception
                    if ($action == 'delete-action') {
                        $this->entityManager->remove($entity);
                    } else {
                        $entity->{$action}();
                        $this->entityManager->persist($entity);
                    }
                }
                $this->entityManager->flush();
            }
        }
    }
}
