<?php

declare(strict_types=1);

namespace Endermanbugzjfc\ImaginaryWorld;

use pocketmine\Server;
use pocketmine\world\format\io\WritableWorldProvider;
use pocketmine\world\format\io\WorldProvider;
use pocketmine\world\format\io\WorldData;
use pocketmine\world\format\io\LoadedChunkData;
use pocketmine\world\format\io\ChunkData;
use pocketmine\world\World;
use pocketmine\math\Vector3;
use pocketmine\world\format\io\exception\CorruptedWorldException;
use pocketmine\world\format\io\exception\UnsupportedWorldFormatException;
use pocketmine\world\format\io\WorldProviderManager;
use pocketmine\world\WorldException;
use Symfony\Component\Filesystem\Path;

class ImaginaryWorld {
    /**
     * Void worlds will not be registered to the {@link WorldManager} as they do not require {@link WorldProvider::close()}ing.
     * Moreover, runtime exceptions might occur if another plugin calls {@link WorldProvider::getPath()} on it.
     * No matter what, they will still take up a world ID.
     */
    public static function newVoid(string $displayName, ?string $providerPath) : World {
        $server = Server::getInstance();
        $rcOriginal = 0;
        return new World($server, $displayName, new ImaginaryWorldProvider(
            originalProvider: null,
            rcOriginal: $rcOriginal,
            name: $displayName,
            path: $providerPath,
            logger: null,
            warnGetPath: false, // This will always crash if $providerPath is null.
            logClose: false,
        ), $server->getAsyncPool());
    }

    /**
     * @throws ImaginaryWorldException
     * @throws WorldException
     * @throws CorruptedWorldException
     * @throws UnsupportedWorldFormatException
     */
    public static function fromTemplate(string $folderName, string $displayName) : World {
        $server = Server::getInstance();
        $manager = $server->getWorldManager();
        if (!$manager->isWorldGenerated($folderName)) {
            throw new ImaginaryWorldException("'$folderName', please consider using ImaginaryWorld::newVoid() if template is unneeded");
        }

        $path = Path::join($server->getDataPath(), "worlds", "folderName");
        $providerManagerReflect = new \ReflectionProperty($manager, "providerManager");
        $providerManagerReflect->setAccessible(true);
        /**
         * @var WorldProviderManager $providerManager
         */
        $providerManager = $providerManagerReflect->getValue($manager);
        $providerClass = array_values($providerManager->getMatchingProviders($path))[0] ?? throw new \RuntimeException("Imaginary world template matched no provider: $path");
        $worldsReflect = new \ReflectionProperty($manager, "worlds");
        $worldsReflect->setAccessible(true);
        $loaded = $manager->getWorldByName($folderName)?->getProvider();

        $rcOriginal = 0;
        $provider = $loaded instanceof ImaginaryWorldProvider
            ? $provider = $loaded->cloneWith($displayName)
            : ($loaded !== null
            ? throw new ImaginaryWorldException("'$folderName' already loaded as writable so cannot be template for imaginary world, please consider using its clone if this is unavoidable")
            : new ImaginaryWorldProvider(
                $providerClass->fromPath($path, new \PrefixedLogger($server->getLogger(), "World Provider: $folderName")),
                $rcOriginal,
                $displayName,
                $path,
                logger: null,
                warnGetPath: false,
                logClose: false,
            )
            ); // 會 Lisp 的和不會 Lisp 的都沉默了...
        $world = new World($server, $displayName, $provider, $server->getAsyncPool());
        $worlds = $worldsReflect->getValue($manager);
        /**
         * @var array<int, World> $worlds
         */
        $worlds[$world->getId()] = $world;
        $worldsReflect->setValue($manager, $worlds);

        return $world;
    }

    /**
     * Please use this class statically.
     */
    private function __construct() {
    }
}

class ImaginaryWorldException extends \Exception {
}

class ImaginaryWorldProvider implements WritableWorldProvider {
    public readonly WorldData $worldData;
    private \Logger $logger;

    public function __construct(
        public readonly ?WorldProvider $originalProvider,
        private int &$rcOriginal,
        string $name,
        private ?string $path,

        ?\Logger $logger,
        public bool $warnGetPath,
        public bool $logClose,
    ) {
        $this->rcOriginal++;
        $this->logger = $logger ?? new \PrefixedLogger(Server::getInstance()->getLogger(), "'$name'");
        $originalData = $originalProvider?->getWorldData();
        $this->worldData = new ImaginaryWorldData(
            $originalData?->getName() ?? $name,
            $originalData?->getSeed(),
            $originalData?->getTime(),
            $originalData?->getSpawn(),
            $originalData?->getDifficulty(),
            $originalData?->getRainTime(),
            $originalData?->getRainLevel(),
            $originalData?->getLightningTime(),
            $originalData?->getLightningLevel(),

            logger: $this->logger,
            logChanges: true,
        );
    }

    public function cloneWith(string $displayName) : self {
        return new self(
            $this->originalProvider,
            $this->rcOriginal,
            $displayName,
            $this->path,
            logger: null,
            warnGetPath: false,
            logClose: false,
        );
    }

    public function getWorldMinY() : int {
        return $this->originalProvider?->getWorldMinY() ?? 0;
    }

    public function getWorldMaxY() : int {
        return $this->originalProvider?->getWorldMaxY() ?? 0;
    }

    public function getPath() : string {
        // Return empty path if you want to eradicate someone's root:
        // https://youtu.be/qzZLvw2AdvM
        $path = $this->originalProvider?->getPath() ?? $this->path ?? throw new \RuntimeException("getPath() called on VOID imaginary world");
        if ($this->warnGetPath) {
            $this->logger->warning("getPath() called on imaginary world provider: $path");
        }
        return $path;
    }

    public function loadChunk(int $chunkX, int $chunkZ) : ?LoadedChunkData {
        // TODO: special logic
        return $this->originalProvider?->loadChunk($chunkX, $chunkZ);
    }

    public function doGarbageCollection() : void {
        $this->originalProvider?->doGarbageCollection();
    }

    public function getWorldData() : WorldData {
        return $this->worldData;
    }

    public function close() : void {
        if ($this->originalProvider === null) {
            return;
        }
        $force = !Server::getInstance()->isRunning();
        if (--$this->rcOriginal < 1 || $force) {
            $this->logger->debug("Closing " . ($force ? "(force) " : "") . "original world provider: {$this->originalProvider->getPath()}");
        }
    }

    public function calculateChunkCount() : int {
        return $this->originalProvider?->calculateChunkCount() ?? 0;
    }

    public function getAllChunks(bool $skipCorrupted = false, ?\Logger $logger = null) : \Generator {
        // TODO
        return $this->originalProvider->getAllChunks($skipCorrupted, $logger);
    }

    public function saveChunk(int $chunkX, int $chunkZ, ChunkData $chunkData, int $dirtyFlags) : void {
        // TODO: read dirty flag
    }
}

class ImaginaryWorldData implements WorldData {
    public function __construct(
        private string $name,
        ?int $seed,
        ?int $time,
        ?Vector3 $spawn,
        ?int $difficulty,
        ?int $rainTime,
        ?float $rainLevel,
        ?int $lightningTime,
        ?float $lightningLevel,

        private \Logger $logger,
        public bool $logChanges,

    ) {
        $this->seed = $seed ?? 0;
        $this->time = $time ?? 0;
        $this->spawn = $spawn ?? new Vector3(0, 1, 0);
        $this->difficulty = $difficulty ?? 0;
        $this->rainTime = $rainTime ?? 0;
        $this->rainLevel = $rainLevel ?? 0.0;
        $this->lightningTime = $lightningTime ?? 0;
        $this->lightningLevel = $lightningLevel ?? 0.0;
    }

    private int $seed;
    private int $time;
    private Vector3 $spawn;
    private int $difficulty;
    private int $rainTime;
    private float $rainLevel;
    private int $lightningTime;
    private float $lightningLevel;

    /**
     * @var mixed[]
     */
    public array $changes = [];

    public function save() : void {
        if (!$this->logChanges) {
            return;
        }
        $this->logger->debug("save() called on imaginary world data: " . json_encode($this->changes));
    }

    public function getName() : string {
        return $this->name;
    }

    public function setName(string $value) : void {
        $this->name = $value;
        $this->changes["name"] = $value;
    }

    public function getGenerator() : string {
        return "FLAT";
    }

    public function getGeneratorOptions() : string {
        return "";
    }

    public function getSeed() : int {
        return $this->seed;
    }

    public function getTime() : int {
        return $this->time;
    }

    public function setTime(int $value) : void {
        $this->time= $value;
        $this->changes["time"] = $value;
    }

    public function getSpawn() : Vector3 {
        return $this->spawn;
    }

    public function setSpawn(Vector3 $pos) : void {
        $this->spawn = $pos;
        $this->changes["spawn"] = $pos;
    }

    public function getDifficulty() : int {
        return $this->difficulty;
    }

    public function setDifficulty(int $difficulty) : void {
        $this->difficulty = $difficulty;
        $this->changes["diffculty"] = $difficulty;
    }

    public function getRainTime() : int {
        return $this->rainTime;
    }

    public function setRainTime(int $ticks) : void {
        $this->rainTime = $ticks;
        $this->changes["rainTime"] = $ticks;
    }

    public function getRainLevel() : float {
        return $this->rainLevel;
    }

    public function setRainLevel(float $level) : void {
        $this->rainLevel = $level;
        $this->changes["rainLevel"] = $level;
    }

    public function getLightningTime() : int {
        return $this->lightningTime;
    }

    public function setLightningTime(int $ticks) : void {
        $this->lightningTime = $ticks;
        $this->changes["lightningTime"] = $ticks;
    }

    public function getLightningLevel() : float {
        return $this->lightningLevel;
    }

    public function setLightningLevel(float $level) : void {
        $this->lightningLevel = $level;
        $this->changes["lightningLevel"] = $level;
    }
}
