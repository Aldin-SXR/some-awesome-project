<?php
use PHPMailer\PHPMailer\Exception;
require_once "Mailer.php";

class PersistanceManager {
    private $pdo;
	/* PDO constructor */
	public function __construct($params) {
		$dsn = "mysql:host=".$params["host"].";dbname=".$params["db"].";charset=".$params["charset"];
		$opt = array(
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		);
		$this->pdo = new PDO($dsn, $params["user"], $params["pass"], $opt);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	/**
	 * Manage SHOPPING actions
	 */
	/* Get basic product info from database */
	public function get_basic_product_info() {
		$stmt = $this->pdo->query("SELECT id, product_name, product_price, product_picture, status FROM products;");
		return $stmt->fetchAll();
	}

	/* Get available categories from database */
	public function get_categories() {
		$stmt = $this->pdo->query("SELECT * FROM categories;");
		return $stmt->fetchAll();
	}

	/* Get available countries and shipping rates from database */
	public function get_countries_and_rates() {
		$stmt = $this->pdo->query("SELECT * FROM countries;");
		return $stmt->fetchAll();
	}

	/* Get detailed product info from database */
	public function get_detailed_product_info($id) {
		$stmt = $this->pdo->prepare("SELECT p.*, c.category_name AS product_category  FROM products AS p INNER JOIN 
															categories AS c ON p.product_category = c.id WHERE p.id = :id;");
		$stmt->execute(array("id" => $id));
		return $stmt->fetch();
	}

	/* Get detailed product info from database */
	public function check_coupon($coupon) {
		$stmt = $this->pdo->prepare("SELECT * FROM coupons WHERE coupon_value = :coupon_value");
		$stmt->execute(array("coupon_value" => $coupon));
		return $stmt->fetch();
	}

	/* Get products based on category */
	public function get_products_via_category($category, $range) {
		$query = "SELECT p.id, product_name, product_price, product_picture, status FROM products AS p INNER JOIN
						categories AS c ON p.product_category = c.id";
		/* if both price range and category were chosen */
		if ($range != "all" && $category != "all") {
			$range = explode("&", $range);
			$query .= " WHERE c.category_name LIKE :category AND p.product_price BETWEEN :start AND :end;";
			$stmt = $this->pdo->prepare($query);
			$stmt->execute(array(
				"category" => $category,
				"start" => $range[0],
				"end" => $range[1]	
			));
		/* if a range was chosen */
		} else if ($range != "all" && $category == "all") {
			$range = explode("&", $range);
			$query .= " WHERE p.product_price BETWEEN :start AND :end;";
			$stmt = $this->pdo->prepare($query);
			$stmt->execute(array(
				"start" => $range[0],
				"end" => $range[1]	
			));
		/* if a category was chosen */
		} else if ($range == "all" && $category != "all") {
			$range = array(0, 200);
			$query .= " WHERE c.category_name LIKE :category;";
			$stmt = $this->pdo->prepare($query);
			$stmt->execute(array(
				"category" => $category
			));
		/* if nothing was chosen */
		} else {
			$stmt = $this->pdo->prepare($query);
			$stmt->execute();
		}
		
		return $stmt->fetchAll();
	}

	/* Place new product(s) order */
	public function place_order($order) {
		$order = json_decode($order["param"], true);
		$order["date_of_purchase"] =  date("Y-m-d H:i:s");
		$stmt = $this->pdo->prepare("INSERT INTO processed_orders(order_content, total_price, shipping_country, shipping_address, buyer_name, buyer_phone, date_of_purchase)
		VALUES (:order_content, :total_price, :shipping_country, :shipping_address, :buyer_name, :buyer_phone, :date_of_purchase);");
		try {
			$this->pdo->query("ALTER TABLE processed_orders AUTO_INCREMENT = 1");
			$stmt->execute($order);
			return array("status" => "success");
		} catch (PDOException $e) {
			print_r($e);
			return array("status" => "error");
		}
	}
	/**
	 * Manage USER actions
	 */
	public function add_new_user($user) {

        /* try for duplicate entities */
        try {
            /* reset autoincrement on every try to avoid skipped indices */
            $this->pdo->query("ALTER TABLE users AUTO_INCREMENT = 1");
            /* prepare and execute the statement */
            $stmt = $this->pdo->prepare("INSERT INTO users (superuser, user_name, email, password, real_name, phone_number, country, address, zipcode, activation_hash, activated, subscribed)
                                                                VALUES (:superuser, :user_name, :email, :password, :real_name, :phone_number, :country, :address, :zipcode, :activation_hash, :activated, :subscribed);");
			$activation_hash = md5(((string)rand(0, 1000)).$user["email"]);
            $stmt->execute(array(
			"superuser" => 0,
            "user_name" => $user["username"],
            "email" => $user["email"],
			"password" => password_hash($user["password"], PASSWORD_DEFAULT),
			"real_name" => (trim($user["realname"]) == "") ? "not provided" : $user["realname"],
			"phone_number" => (trim($user["phone"]) == "") ? "not provided" : $user["phone"],
			"country" => ($user["country"] == "Select country:") ? NULL : $user["country"],
			"address" => (trim($user["address"]) == "") ? "not provided" : $user["address"],
			"zipcode" =>(trim($user["zipcode"]) == "") ? "not provided" : $user["zipcode"],
            "activation_hash" => $activation_hash,
			"activated" => 0,
			"subscribed" => 0
			));
			/* Activation email */
			$activation_mail = "
        	<h3>Thank you for registering to our Flink Web Shop</h3>
        	<hr>As a registered user, you will enjoy a permanent <strong>5% discount</strong> on all Flink products, <br>
        	have the ability to save your cart for later shopping, be able to rate and review our products and have an insight into
        	our latest products and technologies via the official newsletter.<br>
        	<p>Your account has been successfully created and you can log into the shop using your chosen credentials, <br>
        	after you activate your account by clicking on the bottom link.</p>
        	<hr>
        	Flink team wishes you happy and comfortable shopping!
        	<hr>
        	Please click on the bottom link to activate your account:<br>
        	http://www.flinkaj.me/webshop/rest/v1/users/verify/?email=".$user["email"]."&hash=".$activation_hash."";
            /* send activation mail */
            Mailer::mail($user["email"], "Flink Webshop account activation", $activation_mail);
            return array("status" => "success");
        } catch (PDOException $e) {
            /* this error code signifies duplicate entry */
            if ($e->errorInfo[1] == 1062)
                return array("status" => "duplicate");
            else {
				return array("status" => "error");
			}
        }
	}

	/* validate existing user */
	public function validate_user($user) {
		try {
			$stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = :email;");
			$stmt->execute(array(
				"email" => $user["email"],
			));
			$result = $stmt->fetch();
			/* if a user with the given email is found */
			if ($result) {
				/* verify activation */
				if ($result["activated"] == 1) {
					/* verify password */
					if (password_verify($user["password"], $result["password"])) {
						$result["status"] = "success";
						return $result;
					} else
						return array("status" => "pass_incorrect");
				} else {
					return array("status" => "not_activated");
				}
			} else {
				return array("status" => "email_incorrect");
			}
		} catch (PDOException $e) {
			return array("status" => "error");
		}
	}
	
	/* Save a user's cart */
	public function save_cart($cart, $id) {
		$stmt = $this->pdo->prepare("INSERT INTO saved_carts(cart_contents, associated_user) VALUES (:cart_contents, :associated_user)
		ON DUPLICATE KEY UPDATE cart_contents = :new_cart_contents;");
		try {
			$stmt->execute(array(
				"cart_contents" => $cart["cart"],
				"associated_user" => $id,
				"new_cart_contents" => $cart["cart"]
			));
			return array("status" => "success");
		} catch (PDOException $e) {
			return array("status" => "error");
		}
	}

	/* Load user's cart */
	public function load_cart($id) {
		$stmt = $this->pdo->prepare("SELECT * FROM saved_carts WHERE associated_user = :associated_user;");
		try {
			$stmt->execute(array("associated_user" => $id));
			return $stmt->fetch();
		} catch (Exception $e) {	
			return array("status" => "error");
		}
	}

	/* Subscribe user to newsletter */
	public function subscribe_to_newsletter($id) {
		$stmt = $this->pdo->prepare("UPDATE users SET subscribed = subscribed ^ 1 WHERE id = :id");
		try {
			$stmt->execute(array("id" => $id));
			return array("status" => "success");
		} catch (PDOException $e) {
			return array("status" => "error");
		}
	}

	/* Activate a new user via an activaiton link */
	public function activate_user($data) {
        $stmt = $this->pdo->prepare("SELECT activation_hash, activated FROM users WHERE email = :email");
        $stmt->execute(array(
            "email" => $data["email"]
        ));
        $result = $stmt->fetch();
        /* if a user is found */
        if ($result) {
            if ($result["activated"] == 1)
                return array("status" => "already_activated");
            else {
                /* account is not activated; check if has is valid */
                if ($result["activation_hash"] == $data["hash"]) {
                    /* update activated status */
                    $stmt = $this->pdo->prepare("UPDATE users SET activated = :activated WHERE email = :email");
                    $stmt->execute(array(
                        "activated" => 1,
                        "email" => $data["email"]
                    ));
                    return array("status" => "success");
                } else
                    return array("status" => "tampered_with");
            }
        } else {
            return array("status" => "email_incorrect");
        }
    }
}
?>