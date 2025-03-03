<?php

namespace AssistantEngine\OpenFunctions\Bitbucket;

use AssistantEngine\OpenFunctions\Bitbucket\Models\Parameters;
use AssistantEngine\OpenFunctions\Bitbucket\Repository\BitbucketRepository;
use AssistantEngine\OpenFunctions\Core\Contracts\AbstractOpenFunction;
use AssistantEngine\OpenFunctions\Core\Helpers\FunctionDefinition;
use AssistantEngine\OpenFunctions\Core\Helpers\Parameter;
use AssistantEngine\OpenFunctions\Core\Models\Responses\TextResponseItem;

class BitbucketOpenFunction extends AbstractOpenFunction
{
    private $bitbucketRepository;
    private $parameter;

    public function __construct(Parameters $parameters)
    {
        $this->parameter = $parameters;
        $this->bitbucketRepository = new BitbucketRepository($parameters->token, $parameters->owner, $parameters->repository);
    }

    public function listFiles($branchName)
    {
        $this->bitbucketRepository->checkoutBranch($branchName);

        return new TextResponseItem(json_encode($this->bitbucketRepository->listFiles(true)));
    }

    public function readFiles($branchName, array $filenames)
    {
        $this->bitbucketRepository->checkoutBranch($branchName);
        $response = [];
        $fileContents = [];

        foreach ($filenames as $filename) {
            try {
                $content = $this->bitbucketRepository->readFile($filename);
                $fileContents[$filename] = $content;
            } catch (\Exception $e) {
                $fileContents[$filename] = 'Error: Not found';
            }
        }

        foreach ($fileContents as $filename => $content) {
            $response[] = new TextResponseItem(json_encode([$filename => $content]));
        }

        return $response;
    }

    public function commitFiles($branchName, array $files, $commitMessage)
    {
        if (in_array($branchName, $this->parameter->protected)) {
            throw new \Exception("Operation not allowed: The branch '{$branchName}' is protected.");
        }

        $this->bitbucketRepository->checkoutBranch($branchName);

        $this->bitbucketRepository->modifyFiles($files, $commitMessage);

        return new TextResponseItem(json_encode(['success' => true]));
    }

    public function generateFunctionDefinitions(): array
    {
        $branches = $this->bitbucketRepository->listBranches();

        return [
            (new FunctionDefinition(
                'listFiles',
                'List all files in the specified branch. '
            ))
                ->addParameter(
                    Parameter::string('branchName')
                        ->description('The branch to list files from')
                        ->enum($branches)
                        ->required()
                )->createFunctionDescription(),

            (new FunctionDefinition(
                'readFiles',
                'Read contents of specified files from the specified branch.'
            ))
                ->addParameter(
                    Parameter::string('branchName')
                        ->description('The branch to read files from')
                        ->enum($branches)
                        ->required()
                )
                ->addParameter(
                    Parameter::array('filenames')
                        ->description('An array of filenames to read')
                        ->setItems(Parameter::string(null)->description('A filename'))
                        ->required()
                )->createFunctionDescription(),

            (new FunctionDefinition(
             'commitFiles',
                'Modify multiple files and commit them to the specified branch. '
            ))
                ->addParameter(
                    Parameter::string('branchName')
                        ->description('The branch to commit files to')
                        ->enum($branches)
                        ->required()
                )
                ->addParameter(
                    Parameter::array('files')
                        ->description('An array of files to commit')
                        ->setItems(Parameter::object(null)
                            ->description('The file to commit')
                            ->addProperty(Parameter::string('path')->description('The path of the file to commit')->required())
                            ->addProperty(Parameter::string('content')->description('The content of the file to commit')->required())
                        )
                        ->required()
                )
                ->addParameter(
                    Parameter::string('commitMessage')
                        ->description('The commit message')
                        ->required()
                )->createFunctionDescription(),
        ];
    }
}