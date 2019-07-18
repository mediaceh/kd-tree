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


class Face implements FaceInterface {

    protected $id;
    protected $race;
    protected $emotion;
    protected $oldness;
    protected $left;
    protected $right;
    protected $points;

    /**
    * Construct new face.
    * @param int $race
    * @param int $emotion
    * @param int $oldness
    * @param int $id
    */
    public function __construct(int $race,int $emotion,int $oldness,int $id = 0) {
        $this->id = $id;
        $this->race = $race;
        $this->emotion = $emotion;
        $this->oldness = $oldness;
        $this->left = null;
        $this->right = null;
        $this->points = [];
    }
    
    /**
    * Set face id.
    * @param int $id
    */
    public function setId(int $id): void {
        $this->id = $id;
    }

    /**
    * Set left node.
    * @param FaceInterface $face
    */        
    public function setLeft(FaceInterface $face): void {
        $this->left = $face;
    }

    /**
    * Set right node.
    * @param FaceInterface $face
    */        
    public function setRight(FaceInterface $face): void {
        $this->right = $face;
    }
    
    /**
    * Get nth dimension of face.
    * @param int $dim
    * @return int 
    */    
    public function getNthDim(int $dim): int {
        switch($dim) {
            case 1: return $this->race;
            case 2: return $this->emotion;
            case 3: return $this->oldness;
        }
    }
    
    /**
    * Get left node.
    * @return FaceInterface or null
    */        
    public function getLeft(): ?FaceInterface {
        return $this->left;
    }
    
    /**
    * Get right node.
    * @return FaceInterface or null
    */    
    public function getRight(): ?FaceInterface {
        return $this->right;
    }
    
    /**
    * Add face into leaf.
    * @param FaceInterface $face
    */        
    public function addPoint(FaceInterface $face): void {
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
        return $this->id;
    }

    /**
    * Returns race parameter: from 0 to 100.
    * @return int
    */
    public function getRace(): int {
        return $this->race;
    }
    
    /**
    * Returns face emotion level: from 0 to 1000.
    * @return int
    */
    public function getEmotion(): int {
        return $this->emotion;
    }

    /**
    * Returns face oldness level: from 0 to 1000.
    * @return int
    */
    public function getOldness(): int {
        return $this->oldness;
    }
}


class FaceFinder {

    protected $data;
    protected $tree;
    protected $face;
    protected $outerNode;
    protected $innerNode;
    protected $outerRadius;
    protected $innerRadius;
    protected $nodePoint;
    protected $queue;
    protected $radiuses;
    protected $pointsInLeaf;
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
        $this->createDB();
        $this->selectDB();
        $this->createTable();
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
        if(!$face->getId()) {
            $this->store($face);
        }
        return $this->getNearestNeighbor($face);
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
    * @param FaceInterface $face
    */
    protected function store(FaceInterface $face): void {    
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
    * Create db.
    */
    protected function createDB(): void {
        $this->mysql->query("CREATE DATABASE IF NOT EXISTS `face_finder` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
    }

    /**
    * Select db.
    */
    protected function selectDB(): void {
        $this->mysql->select_db("face_finder");
    }

    /**
    * Create table.
    */
    protected function createTable(): void {
        $this->mysql->query("CREATE TABLE IF NOT EXISTS `faces` (
            `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `race` tinyint(4) NOT NULL,
            `emotion` smallint(6) NOT NULL,
            `oldness` smallint(6) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
    * Push face to data array.
    * @param FaceInterface $face
    */
    protected function pushToData(FaceInterface $face): void {
        $data = [$face->getId(),$face->getRace(),$face->getEmotion(),$face->getOldness()];
        if(count($this->data) == self::LIMIT) {
            #отсортируем данные по возрастанию по айди за O(lon(n)), что бы вставить в начало за O(1)
            $this->quickSort($this->data, 0, count($this->data)-1, 0);
            $this->data[0] = $data;
        } else {
            array_push($this->data,$data);
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
                $data[] = $row;
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
    * @return FaceInterface
    */
    protected function buildTreeRecursive(int $dim, int $left, int $right): FaceInterface {
        $dim++;
        $this->quickSort($this->data, $left, $right, $dim);
        $mid = round(($left + $right) / 2);
        while(($mid > $left) && ($this->data[$mid][$dim] == $this->data[$mid-1][$dim])) {
            $mid--;
        }
        $face = new Face($this->data[$mid][1],$this->data[$mid][2],$this->data[$mid][3],$this->data[$mid][0]);
        $dim = $dim % self::DIM_COUNT;
        #если мы имеет необходимое количество точек для каждого листа, то можем разбиваться дальше
        if((($mid-$left) > self::MIN_POINTS) && (($right-$mid) > self::MIN_POINTS)) {
            $face->setLeft($this->buildTreeRecursive($dim,$left,$mid-1));
            $face->setRight($this->buildTreeRecursive($dim,$mid+1,$right));
        #иначе кидаем все точки в лист
        } else {
            for($i = $left; $i <= $right; $i++) {
                $face->addPoint(new Face($this->data[$i][1],$this->data[$i][2],$this->data[$i][3],$this->data[$i][0]));
            }
        }
        return $face;
    }    


    /**
    * Build tree recursive withou quick sort.
    * @param array $data
    * @param int $dim
    * @return FaceInterface
    */
    protected function buildTreeRecursiveWithouQuickSort(array $data, int $dim): FaceInterface {
        $dim++;
        usort($data,function ($a, $b) use ($dim) {
            return $a[$dim] <=> $b[$dim];
        });
        $mid = round((count($data) - 1) / 2);
        while(($mid>0)&&($data[$mid][$dim] == $data[$mid-1][$dim])) {
            $mid--;
        }
        $face = new Face($data[$mid][1],$data[$mid][2],$data[$mid][3],$data[$mid][0]);
        $dim = $dim % self::DIM_COUNT;
        #если мы имеет необходимое количество точек для каждого листа, то можем разбиваться дальше
        if((($mid-1) > self::MIN_POINTS)&&((count($data)-($mid+1)) > self::MIN_POINTS)) {
            $face->setLeft($this->buildTreeRecursiveWithouQuickSort(array_slice($data,0,$mid),$dim));
            $face->setRight($this->buildTreeRecursiveWithouQuickSort(array_slice($data,$mid+1),$dim));
        #иначе кидаем все точки в лист
        } else {
            foreach($data as $point) {
                $face->addPoint(new Face($point[1],$point[2],$point[3],$point[0]));
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
    * @param FaceInterface $face
    * @return array
    */    
    protected function getNearestNeighbor(FaceInterface $face): array {
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $this->face = $face;
        $return = [];
        #если у нас нет дерева просто соберем все данные в очередь
        if(!$this->tree) {
            foreach($this->data as $data) {
                $point = new Face($data[1],$data[2],$data[3],$data[0]);
                $dist = $this->calculateDist($point);
                $this->queue->insert($point,$dist);
            }
        } else {
            $this->innerRadius = -1;
            $this->outerRadius = 0;
            $this->innerNode = null;
            $this->outerNode = null;    
            $this->radiuses = new SplPriorityQueue();
            $this->pointsInLeaf = new SplPriorityQueue();
            $this->radiuses->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
            $this->pointsInLeaf->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
            $this->getNearestNeighborRecursive($this->tree);
        }
        while($this->queue->count()) {
            $return[] = $this->queue->extract();
        }
        #если наша лучшая точка это не точка из запроса или у нас вообще нет точек, то добавим нашу точку
        if(!count($return) || ($return[count($return)-1]->getId() != $this->face->getId())) {
            $return[] = $this->face;
        }
        #вернем пять последних элементов в обратном порядке
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
    * @param FaceInterface $tree
    * @param int $dim
    */    
    protected function getNearestNeighborRecursive(FaceInterface $tree,int $dim = 0): void {
        $dim++;
        if($this->face->getNthDim($dim) >= $tree->getNthDim($dim)) {
            $first = $tree->getRight();
            $second = $tree->getLeft();
        } else {
            $first = $tree->getLeft();
            $second = $tree->getRight();
        }
        if($first) {
            $this->getNearestNeighborRecursive($first,$dim%self::DIM_COUNT);
            $distToSplitterObject = ($tree->getNthDim($dim) - $this->face->getNthDim($dim))**2;
            #если внешний радиус пересекает объект разбиения пространства, 
            #то нам нужно посмотреть с другой стороны
            if(($this->outerRadius > $distToSplitterObject) && $second) {    
                $this->nodePoint = $tree;
                $this->getNearestNeighborRecursive($second,$dim%self::DIM_COUNT);
            }
        } else {
            #если мы первый раз оказались в листе
            #то нам нужно установить внутренний и внешний радиусы
            #а так же запомнить оставшиеся что бы переходить на них
            if(!$this->outerRadius) {
                foreach($tree->getPoints() as $point) {
                    $dist = $this->calculateDist($point);
                    $this->radiuses->insert($point,$dist);
                    $this->queue->insert($point,$dist);
                }
                while($this->radiuses->count() > self::MIN_POINTS) {
                    $queueData = $this->radiuses->extract();    
                }
                $this->outerRadius = $queueData['priority'];
                $this->outerNode = $queueData['data'];
                $queueData = $this->radiuses->extract();    
                $this->innerRadius = $queueData['priority'];
                $this->innerNode = $queueData['data'];
            } else {
                #данный код может быть немного улучшен, но на это нет времени
                #улучшения касаются того, что мы можем набрать лишних радиусов
                #вместо того, что бы сужать поиск
                #если мы оказались в другом листе
                #переберем все точки включая точку разбиения
                $tree->addPoint($this->nodePoint);
                foreach($tree->getPoints() as $point) {
                    $dist = $this->calculateDist($point);
                    if($dist < $this->outerRadius) {
                        #если внутренний радиус равен нулю
                        #значит мы не можем сужать поиск
                        #пробуем набрать необходимое количество точек внутри радиуса
                        #что бы иметь возможность установить внутренний радиус и сузить поиск
                        if($this->innerRadius == 0) {
                            $this->radiuses->insert($point,$dist);
                        } else {
                            $this->pointsInLeaf->insert($point,$dist);
                        }
                        $this->queue->insert($point,$dist);
                    }
                }
                #если мы набрали необходимое количество точек
                #то мы можем установить внутренний радиус
                if(($this->innerRadius == 0) && ($this->radiuses->count() >= self::MIN_POINTS)) {
                    $queueData = $this->radiuses->extract();    
                    $this->innerRadius = $queueData['priority'];
                    $this->innerNode = $queueData['data'];

                #если внутренний радиус не равен нулю
                #то мы имеем возможность сузить поиск
                } else if($this->innerRadius != 0) {
                    #начинаем с худших точек
                    while($this->pointsInLeaf->count()) {
                        $queueData = $this->pointsInLeaf->extract();    
                        $dist = $queueData['priority'];
                        $point = $queueData['data'];
                        #если наша точка оказалась между двумя радиусами
                        #то можно заменить внешний радиус на нее ничего не потеряв
                        if(($dist > $this->innerRadius) && ($dist < $this->outerRadius)) {
                            $this->outerRadius = $dist;
                            $this->outerNode = $point;
                        #если наша точка оказалась за внутренним радиусом
                        #то во первых нам надо проверить есть ли у нас еще радиусы для улучшения
                        } else if($this->radiuses->count() && ($dist < $this->innerRadius)) {
                            $this->outerRadius = $this->innerRadius;
                            $this->outerNode = $this->innerNode;
                            $queueData = $this->radiuses->extract();    
                            #если радиусы есть, но наш следующий радиус находится за точкой
                            #то нам нужно установить эту точку в качестве внутреннего радиуса
                            #и вернуть наш радиус о очередь
                            #иначе мы потеряем возможные лучшие варианты
                            if($queueData['priority'] < $dist) {
                                $this->innerRadius = $dist;
                                $this->innerNode = $point;
                                $this->radiuses->insert($queueData['data'],$queueData['priority']);
                            #иначе все ок
                            } else {
                                $this->innerRadius = $queueData['priority'];
                                $this->innerNode = $queueData['data'];
                            }
                        #если радиусов больше нет, но внутренний радиус по прежнему не равен нулю,
                        #и наша точка оказалась за внутренним радиусом
                        #то надо сбросить его на ноль
                        } else if($dist < $this->innerRadius) {
                            $this->outerRadius = $this->innerRadius;
                            $this->outerNode = $this->innerNode;
                            $this->innerRadius = 0;
                            $this->innerNode = null;
                        }
                    }
                }    
            }
        }
    }
}
?>
