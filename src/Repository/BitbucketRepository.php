<?php

namespace AssistantEngine\OpenFunctions\Bitbucket\Repository;

use Bitbucket\Client;
use Bitbucket\Exception\ExceptionInterface;
use Bitbucket\HttpClient\Message\FileResource;

class BitbucketRepository
{
    private $client;
    private $workspace; // Owner is referred to as workspace in Bitbucket
    private $repo;
    private $baseBranch;
    private $branch; // Active branch

    public function __construct($token, $workspace, $repo, $baseBranch = 'main')
    {
        $this->client = new Client();
        $this->client->authenticate(Client::AUTH_OAUTH_TOKEN, $token);

        $this->workspace = $workspace;
        $this->repo = $repo;
        $this->baseBranch = $baseBranch;
        $this->branch = $baseBranch; // Default to base branch
    }

    // Check if a branch exists
    public function branchExists($branchName)
    {
        try {
            $branch = $this->client->repositories()
                ->workspaces($this->workspace)
                ->refs($this->repo)
                ->branches()
                ->show($branchName);

            return $branch['target']['hash'];
        } catch (ExceptionInterface $e) {
            if ($e->getCode() == 404) {
                return false;
            } else {
                throw $e;
            }
        }
    }

    // List all branches
    public function listBranches()
    {
        try {
            $response = $this->client->repositories()
                ->workspaces($this->workspace)
                ->refs($this->repo)
                ->branches()
                ->list();
            $branches = $response['values'];
            return array_map(function ($branch) {
                return $branch['name'];
            }, $branches);
        } catch (ExceptionInterface $e) {
            throw $e;
        }
    }

    // Checkout branch (create if not exists)
    public function checkoutBranch($branchName)
    {
        $this->branch = $branchName;

        if ($this->branchExists($branchName)) {

        } else {
            // Create branch from base branch
            $this->createBranch($branchName, $this->baseBranch);
        }
    }

    // Create a new branch from another branch
    private function createBranch($newBranch, $sourceBranch = 'main')
    {
        $sourceBranch = $sourceBranch ?? $this->branch;

        // Get the commit hash of the source branch
        $sourceBranchRef = $this->client->repositories()
            ->workspaces($this->workspace)
            ->refs($this->repo)
            ->branches()
            ->show($sourceBranch);

        $sourceCommitHash = $sourceBranchRef['target']['hash'];

        // Create the new branch
        $newBranch = $this->client->repositories()
            ->workspaces($this->workspace)
            ->refs($this->repo)
            ->branches()
            ->create([
                'name' => $newBranch,
                'target' => [
                    'hash' => $sourceCommitHash
                ],
            ]);

        return $newBranch['target']['hash'];
    }



    // List all files in the repository
    public function listFiles($onlyFiles = false)
    {
        try {
            $files = [];
            $this->fetchTree('', $files, $onlyFiles);
            return $files;
        } catch (ExceptionInterface $e) {
            throw $e;
        }
    }

    private function fetchTree($path, &$files, $onlyFiles)
    {
        if ($path === '') {
            $path = '.';
        }



        try {
            // Use the root directory endpoint
            $response = $this->client->repositories()
                ->workspaces($this->workspace)
                ->src($this->repo)
                ->show($this->branch, $path, [
                    'format' => 'content',
                    'pagelen' => 100,
                ]);

            // Handle pagination
            $this->processResponse($response, $files, $onlyFiles);


        } catch (ExceptionInterface $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
            // If the path does not exist, we can ignore it
        }
    }

    private function processResponse($response, &$files, $onlyFiles)
    {
        if (isset($response['type']) && $response['type'] === 'error') {
            // Handle error in response
            return;
        }

        if (isset($response['values'])) {
            foreach ($response['values'] as $item) {
                if ($item['type'] == 'commit_directory') {
                    if (!$onlyFiles) {
                        $files[] = $item['path'];
                    }
                    // Recurse into subdirectory
                    $this->fetchTree($item['path'], $files, $onlyFiles);
                } elseif ($item['type'] == 'commit_file') {
                    $files[] = $item['path'];
                }
            }
        } elseif (isset($response['type']) && $response['type'] == 'commit_file') {
            // It's a single file, add to files list
            $files[] = $response['path'];
        }
    }

    // Read file content
    public function readFile($filePath)
    {
        try {
            $contentStream = $this->client->repositories()
                ->workspaces($this->workspace)
                ->src($this->repo)
                ->download($this->branch, $filePath);

            return $contentStream->getContents();
        } catch (ExceptionInterface $e) {
            throw $e;
        }
    }

    // Modify or create multiple files in a single commit
    public function modifyFiles(array $files, $commitMessage)
    {
        $params = [
            'message' => $commitMessage,
            'branch' => $this->branch,
        ];

        $fileResources = [];

        foreach ($files as $file) {
            $filePath = $file['path'];
            $newContent = $file['content'];

            // Ensure the file path starts with a leading slash
            if (strpos($filePath, '/') !== 0) {
                $filePath = '/' . $filePath;
            }

            $fileResources[] = new FileResource($filePath, $newContent);
        }

        try {
            $this->client->repositories()
                ->workspaces($this->workspace)
                ->src($this->repo)
                ->createWithFiles($fileResources, $params);
        } catch (ExceptionInterface $e) {
            throw $e;
        }
    }
    
}