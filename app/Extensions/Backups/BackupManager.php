<?php

namespace Pterodactyl\Extensions\Backups;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Webmozart\Assert\Assert;
use InvalidArgumentException;
use Aws\S3\S3MultiRegionClient;
use League\Flysystem\AdapterInterface;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Memory\MemoryAdapter;
use Illuminate\Contracts\Config\Repository;

class BackupManager
{
    /**
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * @var \Illuminate\Contracts\Config\Repository
     */
    protected $config;

    /**
     * The array of resolved backup drivers.
     *
     * @var \League\Flysystem\AdapterInterface[]
     */
    protected $adapters = [];

    /**
     * The registered custom driver creators.
     *
     * @var array
     */
    protected $customCreators;

    /**
     * BackupManager constructor.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $app->make(Repository::class);
    }

    /**
     * Returns a backup adapter instance.
     *
     * @param string|null $name
     * @return \League\Flysystem\AdapterInterface
     */
    public function adapter(string $name = null)
    {
        return $this->get($name ?: $this->getDefaultAdapter());
    }

    /**
     * Set the given backup adapter instance.
     *
     * @param string $name
     * @param \League\Flysystem\AdapterInterface $disk
     * @return $this
     */
    public function set(string $name, $disk)
    {
        $this->adapters[$name] = $disk;

        return $this;
    }

    /**
     * Gets a backup adapter.
     *
     * @param string $name
     * @return \League\Flysystem\AdapterInterface
     */
    protected function get(string $name)
    {
        return $this->adapters[$name] = $this->resolve($name);
    }

    /**
     * Resolve the given backup disk.
     *
     * @param string $name
     * @return \League\Flysystem\AdapterInterface
     */
    protected function resolve(string $name)
    {
        $config = $this->getConfig($name);

        if (empty($config['adapter'])) {
            throw new InvalidArgumentException(
                "Backup disk [{$name}] does not have a configured adapter."
            );
        }

        $adapter = $config['adapter'];

        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($config);
        }

        $adapterMethod = 'create' . Str::studly($adapter) . 'Adapter';
        if (method_exists($this, $adapterMethod)) {
            $instance = $this->{$adapterMethod}($config);

            Assert::isInstanceOf($instance, AdapterInterface::class);

            return $instance;
        }

        throw new InvalidArgumentException("Adapter [{$adapter}] is not supported.");
    }

    /**
     * Calls a custom creator for a given adapter type.
     *
     * @param array $config
     * @return \League\Flysystem\AdapterInterface
     */
    protected function callCustomCreator(array $config)
    {
        $adapter = $this->customCreators[$config['adapter']]($this->app, $config);

        Assert::isInstanceOf($adapter, AdapterInterface::class);

        return $adapter;
    }

    /**
     * Creates a new wings adapter.
     *
     * @param array $config
     * @return \League\Flysystem\AdapterInterface
     */
    public function createWingsAdapter(array $config)
    {
        return new MemoryAdapter(null);
    }

    /**
     * Creates a new S3 adapter.
     *
     * @param array $config
     * @return \League\Flysystem\AdapterInterface
     */
    public function createS3Adapter(array $config)
    {
        $config['version'] = 'latest';

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $config['credentials'] = Arr::only($config, ['key', 'secret', 'token']);
        }

        $client = new S3MultiRegionClient($config);

        return new AwsS3Adapter($client, $config['bucket'], $config['prefix'] ?? '', $config['options'] ?? []);
    }

    /**
     * Returns the configuration associated with a given backup type.
     *
     * @param string $name
     * @return array
     */
    protected function getConfig(string $name)
    {
        return $this->config->get("backups.disks.{$name}") ?: [];
    }

    /**
     * Get the default backup driver name.
     *
     * @return string
     */
    public function getDefaultAdapter()
    {
        return $this->config->get('backups.default');
    }

    /**
     * Set the default session driver name.
     *
     * @param string $name
     */
    public function setDefaultAdapter(string $name)
    {
        $this->config->set('backups.default', $name);
    }

    /**
     * Unset the given adapter instances.
     *
     * @param string|string[] $adapter
     * @return $this
     */
    public function forget($adapter)
    {
        foreach ((array) $adapter as $adapterName) {
            unset($this->adapters[$adapter]);
        }

        return $this;
    }

    /**
     * Register a custom adapter creator closure.
     *
     * @param string $adapter
     * @param \Closure $callback
     * @return $this
     */
    public function extend(string $adapter, Closure $callback)
    {
        $this->customCreators[$adapter] = $callback;

        return $this;
    }
}