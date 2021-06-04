<?php
interface FaceInterface {
    /**
    * Returns face id or 0, if face is new
    */
    public function getId(): int;
    /**
    * Returns race parameter: from 0 to 100.
    */
    public function getRace(): int;
    /**
    * Returns face emotion level: from 0 to 1000.
    */
    public function getEmotion(): int;
    /**
    * Returns face oldness level: from 0 to 1000.
    */
    public function getOldness(): int;
}

interface FaceFinderInterface {
    /**
    * Finds 5 most similar faces in DB.
    * If the specified face is new (id=0),
    * then it will be saved to DB.
    *
    * @param FaceInterface $face Face to find and save (if id=0)
    * @return FaceInterface[] List of 5 most similar faces,
    * including the searched one
    */
    public function resolve(FaceInterface $face): array;
    /**
    * Removes all faces in DB
    */
    public function flush(): void;
}


class Face implements FaceInterface, ArrayAccess {

    protected $left = null;
    protected $right = null;
    protected $points = [];
    protected $container = [];

    /**
    * Construct new face.
    * @param int $race
    * @param int $emotion
    * @param int $oldness
    * @param int $id
    */
    public function __construct(int $race,int $emotion,int $oldness,int $id = 0) {
        $this->setId($id);
        $this->setRace($race);
        $this->setEmotion($emotion);
        $this->setOldness($oldness);
    }

    /**
    * Set face id.
    * @param int $id
    */
    public function setId(int $id): void {
        if ($id < 0) {
            throw new RangeException("ID must not be negative");
        }
        $this->container[0] = $id;
    }

    /**
    * Set race.
    * @param int $race
    */
    protected function setRace(int $race): void {
        if ($race > 100 || $race < 0) {
            throw new RangeException("Race must be between 0 and 100");
        }
        $this->container[1] = $race;
    }

    /**
    * Set emotion.
    * @param int $emotion
    */
    protected function setEmotion(int $emotion): void {
        if ($emotion > 1000 || $emotion < 0) {
            throw new RangeException("Emotion must be between 0 and 1000");
        }
        $this->container[2] = $emotion;
    }

    /**
    * Set oldness.
    * @param int $oldness
    */
    protected function setOldness(int $oldness): void {
        if ($oldness > 1000 || $oldness < 0) {
            throw new RangeException("Oldness must be between 0 and 1000");
        }
        $this->container[3] = $oldness;
    }

    /**
    * Set left node.
    * @param Face $face
    */        
    public function setLeft(Face $face): void {
        $this->left = $face;
    }

    /**
    * Set right node.
    * @param Face $face
    */        
    public function setRight(Face $face): void {
        $this->right = $face;
    }

    /**
    * Offset set.
    * @param int $offset
    * @param mixed $value
    */
    public function offsetSet($offset,$value) {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }

    /**
    * Offset exists.
    * @param int $offset
    * @return boolean
    */    
    public function offsetExists($offset) {
        return isset($this->container[$offset]);
    }

    /**
    * Offset unset.
    * @param int $offset
    */
    public function offsetUnset($offset) {
        unset($this->container[$offset]);
    }

    /**
    * Offset get.
    * @param int $offset
    * @return mixed
    */    
    public function offsetGet($offset) {
        return isset($this->container[$offset]) ? $this->container[$offset] : null;
    }

    /**
    * Get nth dimension of face.
    * @param int $dim
    * @return int 
    */    
    public function getNthDim(int $dim): int {
        return $this->container[$dim];
    }

    /**
    * Get left node.
    * @return Face or null
    */        
    public function getLeft(): ?Face {
        return $this->left;
    }

    /**
    * Get right node.
    * @return Face or null
    */    
    public function getRight(): ?Face {
        return $this->right;
    }

    /**
    * Add face into leaf.
    * @param Face $face
    */        
    public function addPoint(Face $face): void {
        $this->points[] = $face;
    }

    /**
    * Returns faces in leaf.
    * @return array
    */    
    public function getPoints(): array {
        return $this->points;
    }

    /**
    * Returns face id or 0, if face is new
    * @return int
    */    
    public function getId(): int {
        return $this->container[0];
    }

    /**
    * Returns race parameter: from 0 to 100.
    * @return int
    */
    public function getRace(): int {
        return $this->container[1];
    }

    /**
    * Returns face emotion level: from 0 to 1000.
    * @return int
    */
    public function getEmotion(): int {
        return $this->container[2];
    }

    /**
    * Returns face oldness level: from 0 to 1000.
    * @return int
    */
    public function getOldness(): int {
        return $this->container[3];
    }
}


class FaceFinder implements FaceFinderInterface {

    protected $data;
    protected $tree;
    protected $face;
    protected $outerNode;
    protected $outerRadius;
    protected $nodePoint;
    protected $queue;
    protected $mysql;
    const MIN_POINTS = 4;
    const LIMIT = 10000;
    const DIM_COUNT = 3;
    
    /**
    * Construct FaceFinder.
    * @param String $host
    * @param String $user
    * @param String $password
    */        
    public function __construct(string $host = 'localhost',string $user = 'root',string $password = '') {
        $this->mysql = $this->connect($host,$user,$password);
        $this->prepareDB();
        $this->data = $this->loadData();
        $this->buildTree();
    }

    /**
    * Finds 5 most similar faces in DB.
    * If the specified face is new (id=0),
    * then it will be saved to DB.
    *
    * @param FaceInterface $face Face to find and save (if id=0)
    * @return FaceInterface[] List of 5 most similar faces,
    * including the searched one
    */
    public function resolve(FaceInterface $face): array {
        $face = new Face($face->getRace(),$face->getEmotion(),$face->getOldness(),$face->getId());
        if(!$face->getId()) {
            $this->store($face);
        }
        $result = $this->getNearestNeighbor($face);
        $test = $this->getNearestNeighborFromDB($face);
        return $result;
    }
    
    /**
    * Get nearest neighbors from DB
    * @param Face $face
    * @return array
    */
    public function getNearestNeighborFromDB(Face $face): array {
        return [];
    }
    
    /**
    * Removes all faces in DB
    */
    public function flush(): void {
        $this->mysql->query("TRUNCATE `faces`");
        $this->clearData();
        $this->destroyTree();
    }

    /**
    * Store face in db.
    * @param Face $face
    */
    protected function store(Face $face): void {
        $race = $face->getRace();
        $emotion = $face->getEmotion();
        $oldness = $face->getOldness();
        $query = $this->mysql->prepare("INSERT INTO `faces` (`race`,`emotion`,`oldness`) VALUES (?,?,?)");
        $query->bind_param('iii',$race, $emotion, $oldness);
        $query->execute();
        $query->close();
        $face->setId($this->mysql->insert_id);
        $this->pushToData($face);
        #не будем добавлять лист, а пересоберем дерево
        $this->destroyTree();
        $this->buildTree();
    }

    /**
    * Connect to db.
    * @param String $host
    * @param String $user
    * @param String $password
    * @return mysqli
    */
    protected function connect(string $host,string $user,string $password): mysqli {
        return new mysqli($host, $user, $password);
    }

    /**
    * Prepare db.
    */    
    protected function prepareDB(): void {
        $this->mysql->multi_query("
            CREATE DATABASE IF NOT EXISTS `face_finder` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
            USE `face_finder`;
            CREATE TABLE IF NOT EXISTS `faces` (
                `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `race` tinyint(4) NOT NULL,
                `emotion` smallint(6) NOT NULL,
                `oldness` smallint(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
        while ($this->mysql->next_result()) {
        }
    }

    /**
    * Push face to data array.
    * @param Face $face
    */
    protected function pushToData(Face $face): void {
        if(count($this->data) == self::LIMIT) {
            #отсортируем данные по возрастанию по айди за O(lon(n)), что бы вставить в начало за O(1)
            $this->quickSort($this->data, 0, count($this->data)-1, 0);
            $this->data[0] = $face;
        } else {
            array_push($this->data,$face);
        }
    }

    /**
    * Load data from db.
    * @return array
    */
    protected function loadData(): array {
        $data = [];
        if ($result = $this->mysql->query("SELECT `id`, `race`, `emotion`, `oldness` FROM `faces` ORDER BY `id` DESC LIMIT ".self::LIMIT."")) {
            while ($row = $result->fetch_row()) {
                $data[] = new Face($row[1],$row[2],$row[3],$row[0]);
            }
            $result->close();
        }
        return $data;
    }

    /**
    * Build tree.
    */
    protected function buildTree(): void {
        #если нам не хватает на корень и два листа - не будем ничего строить
        if(count($this->data) < (self::MIN_POINTS*2 + 3)) {
            $this->tree = null;
        } else {
            $this->tree = $this->buildTreeRecursive(0,0,count($this->data)-1);
        }
    }

    /**
    * Quick sort.
    * @param array &$data
    * @param int $left
    * @param int $right
    * @param int $dim
    */
    protected function quickSort(array &$data, int $left, int $right, int $dim): void {
        $l = $left;
        $r = $right;
        $center = $data[round(($left + $right) / 2)][$dim];
        do {
            while ($data[$r][$dim] > $center) {
              $r--;
            }
            while ($data[$l][$dim] < $center) { 
                $l++;
            }
            if ($l <= $r) {
                list($data[$r], $data[$l]) = [$data[$l], $data[$r]];
                $l++;
                $r--;
            }
        } while ($l <= $r);
        if ($r > $left) {
            $this->quickSort($data, $left, $r, $dim); 
        }
        if ($l < $right) {
            $this->quickSort($data, $l, $right, $dim);
        }
    }

    /**
    * Build tree recursive.
    * @param int $dim
    * @param int $left
    * @param int $right
    * @return Face
    */
    protected function buildTreeRecursive(int $dim, int $left, int $right): Face {
        $dim++;
        $this->quickSort($this->data, $left, $right, $dim);
        $mid = round(($left + $right) / 2);
        while(($mid > $left) && ($this->data[$mid][$dim] == $this->data[$mid-1][$dim])) {
            $mid--;
        }
        $face = $this->data[$mid];
        $dim = $dim % self::DIM_COUNT;
        #если мы имеет необходимое количество точек для каждого листа, то можем разбиваться дальше
        if((($mid-$left) > self::MIN_POINTS) && (($right-$mid) > self::MIN_POINTS)) {
            $face->setLeft($this->buildTreeRecursive($dim,$left,$mid-1));
            $face->setRight($this->buildTreeRecursive($dim,$mid+1,$right));
        #иначе кидаем все точки в лист
        } else {
            for($i = $left; $i <= $right; $i++) {
                $face->addPoint($this->data[$i]);
            }
        }
        return $face;
    }    

    /**
    * Build tree recursive withou quick sort.
    * @param array $data
    * @param int $dim
    * @return Face
    */
    protected function buildTreeRecursiveWithouQuickSort(array $data, int $dim): Face {
        $dim++;
        usort($data,function ($a, $b) use ($dim) {
            return $a[$dim] <=> $b[$dim];
        });
        $mid = round((count($data) - 1) / 2);
        while(($mid>0)&&($data[$mid][$dim] == $data[$mid-1][$dim])) {
            $mid--;
        }
        $face = $data[$mid];
        $dim = $dim % self::DIM_COUNT;
        #если мы имеет необходимое количество точек для каждого листа, то можем разбиваться дальше
        if((($mid-1) > self::MIN_POINTS)&&((count($data)-($mid+1)) > self::MIN_POINTS)) {
            $face->setLeft($this->buildTreeRecursiveWithouQuickSort(array_slice($data,0,$mid),$dim));
            $face->setRight($this->buildTreeRecursiveWithouQuickSort(array_slice($data,$mid+1),$dim));
        #иначе кидаем все точки в лист
        } else {
            foreach($data as $point) {
                $face->addPoint($point);
            }
        }
        return $face;
    }

    /**
    * Add node to tree.
    */    
    protected function addNode(): void {
        //do nothing
    }

    /**
    * Clear data array.
    */
    protected function clearData(): void {
        $this->data = [];
    }

    /**
    * Destroy tree.
    */
    protected function destroyTree(): void {
        $this->tree = null;
    }

    /**
    * Get nearest neighbors
    * @param Face $face
    * @return array
    */    
    protected function getNearestNeighbor(Face $face): array {
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->face = $face;
        $return = [];
        #если у нас нет дерева просто соберем все данные в очередь
        if(!$this->tree) {
            foreach($this->data as $face) {
                $dist = $this->calculateDist($face);
                $this->queue->insert($face,$dist);
            }
        } else {
            $this->outerRadius = 0;
            $this->outerNode = null;    
            $this->getNearestNeighborRecursive($this->tree);
        }
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        while($this->queue->count()) {
            $return[] = $this->queue->extract();
        }
        #если наша лучшая точка это не точка из запроса или у нас вообще нет точек, то добавим нашу точку
        if(!count($return) || ($return[count($return)-1]->getId() != $this->face->getId())) {
            $return[] = $this->face;
        }
        #вернем MIN_POINTS+1 последних элементов в обратном порядке
        return array_reverse(array_slice($return, -(self::MIN_POINTS+1)));
    }

    /**
    * Calculate distance from query point
    * @param FaceInterface $face
    * @return int
    */
    protected function calculateDist(FaceInterface $face): int {
        return ($this->face->getRace() - $face->getRace())**2 + ($this->face->getEmotion() - $face->getEmotion())**2 + ($this->face->getOldness() - $face->getOldness())**2;
    }

    /**
    * Get nearest neighbors recursive
    * @param Face $tree
    * @param int $dim
    */    
    protected function getNearestNeighborRecursive(Face $tree,int $dim = 0): void {
        $dim++;
        if($this->face[$dim] >= $tree[$dim]) {
            $first = $tree->getRight();
            $second = $tree->getLeft();
        } else {
            $first = $tree->getLeft();
            $second = $tree->getRight();
        }
        if($first) {
            $this->getNearestNeighborRecursive($first,$dim%self::DIM_COUNT);
            $distToSplitterObject = ($tree[$dim] - $this->face[$dim])**2;
            #если внешний радиус пересекает объект разбиения пространства, 
            #то нам нужно посмотреть с другой стороны
            if(($this->outerRadius > $distToSplitterObject) && $second) {
                $this->nodePoint = $tree;
                $this->getNearestNeighborRecursive($second,$dim%self::DIM_COUNT);
            }
        } else {
            if(!$this->outerRadius) {
                foreach($tree->getPoints() as $point) {
                    $dist = $this->calculateDist($point);
                    $this->queue->insert($point,$dist);
                }
                while($this->queue->count() > self::MIN_POINTS) {
                    $queueData = $this->queue->extract();
                }
                $this->outerRadius = $queueData['priority'];
                $this->outerNode = $queueData['data'];    
            } else {
                $tree->addPoint($this->nodePoint);
                foreach($tree->getPoints() as $point) {
                    $dist = $this->calculateDist($point);
                    if($dist < $this->outerRadius) {
                        $this->queue->insert($point,$dist);
                        $queueData = $this->queue->extract();
                        $this->outerRadius = $queueData['priority'];
                        $this->outerNode = $queueData['data'];
                    }
                }
            }
        }
    }
}
?>
