<?php
	include("Telegram.php");
	//$api_path = Mage::getModuleDir('', 'Werules_Chatbot') . DS . "Model" . DS . "Api" . DS . "witAI" . DS;
	//include($api_path . "witAI.php");

	class Werules_Chatbot_Model_Api_Telegram_Handler extends Werules_Chatbot_Model_Chatdata
	{
		public function _construct()
		{
			//parent::_construct();
			//$this->_init('chatbot/api_telegram_handler'); // this is location of the resource file.
		}

		public function foreignMessageToSupport($chat_id, $text, $api_name, $customer_name)
		{
			$chatdata = Mage::getModel('chatbot/chatdata');
			if ($api_name == $chatdata->_fbBot && $chat_id)
			{
				$chatdata->load($chat_id, 'facebook_chat_id');
				if (is_null($chatdata->getFacebookChatId()))
				{ // should't happen
					$chatdata->updateChatdata("facebook_chat_id", $chat_id);
				}
			}

			$chatdata->_apiType = $chatdata->_tgBot;
			$apiKey = $chatdata->getApikey($chatdata->_apiType); // get telegram bot api
			if ($apiKey)
			{
				$telegram = new Telegram($apiKey);

				$magehelper = Mage::helper('core');
				$supportgroup = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_support_group');
				if (!empty($supportgroup))
				{
					try{
						if ($supportgroup[0] == "g") // remove the 'g' from groupd id, and add '-'
							$supportgroup = "-" . ltrim($supportgroup, "g");

						$message = $magehelper->__("Message via") . " " . $api_name . ":\n" . $magehelper->__("From") . ": " . $customer_name . "\n" . $text;
						$result = $telegram->sendMessage(array('chat_id' => $supportgroup, 'text' => $message));
						$mid = $result['result']['message_id'];
						if (!empty($mid))
						{
							$chatdata->updateChatdata("last_support_message_id", $mid);
							$chatdata->updateChatdata("last_support_chat", $api_name);
						}
					}
					catch (Exception $e){
						return false;
					}

					return true;
				}
			}

			return false;
		}

		public function telegramHandler($apiKey)
		{
			// Instances the Telegram class
			$telegram = new Telegram($apiKey);

			// Take text and chat_id from the message
			$text = $telegram->Text();
			$chatId = $telegram->ChatID();
			$messageId = $telegram->MessageID();

			// configs
			//$enable_witai = Mage::getStoreConfig('chatbot_enable/witai_config/enable_witai');
			$enableLog = Mage::getStoreConfig('chatbot_enable/general_config/enable_post_log');
			$enableEmptyCategoriesListing = Mage::getStoreConfig('chatbot_enable/general_config/list_empty_categories');
			$enableFinalMessage2Support = Mage::getStoreConfig('chatbot_enable/general_config/enable_support_final_message');
			$supportGroupdId = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_support_group');
			$showMore = 0;
			$cat_id = null;
			$moreOrders = false;
			$listingLimit = 5;
			$listMoreCategories = "/lmc_";
			$listMoreSearch = "/lms_";
			$listMoreOrders = "/lmo_";

			if ($enableLog == "1") // log all posts
				Mage::log("Post Data:\n" . var_export($telegram->getData(), true) . "\n\n", null, 'chatbot_telegram.log');

			if (!is_null($text) && !is_null($chatId))
			{
				// Instances the model class
				$chatdata = Mage::getModel('chatbot/chatdata')->load($chatId, 'telegram_chat_id');
				$chatdata->_apiType = $chatdata->_tgBot;

				if ($messageId == $chatdata->getTelegramMessageId()) // prevents to reply the same request twice
					return $telegram->respondSuccess();
				else if ($chatdata->getTelegramChatId())
					$chatdata->updateChatdata('telegram_message_id', $messageId); // if this fails, it may send the same message twice

				// send feedback to user
				$telegram->sendChatAction(array('chat_id' => $chatId, 'action' => 'typing'));

				// show more handler, may change the conversation state
				if ($chatdata->getTelegramConvState() == $chatdata->_listProductsState || $chatdata->getTelegramConvState() == $chatdata->_listOrdersState) // listing products
				{
					if ($chatdata->checkCommandWithValue($text, $listMoreCategories))
					{
						if ($chatdata->updateChatdata('telegram_conv_state', $chatdata->_listCategoriesState))
						{
							$value = $this->getCommandValue($text, $listMoreCategories);
							$arr = explode("_", $value);
							$cat_id = (int)$arr[0]; // get category id
							$showMore = (int)$arr[1]; // get where listing stopped
						}
					}
					else if ($chatdata->checkCommandWithValue($text, $listMoreSearch))
					{
						if ($chatdata->updateChatdata('telegram_conv_state', $chatdata->_searchState))
						{
							$value = $this->getCommandValue($text, $listMoreSearch);
							$arr = explode("_", $value);
							$showMore = (int)end($arr); // get where listing stopped
							$value = str_replace("_" . (string)$showMore, "", $value);
							$text = str_replace("_", " ", $value); // get search criteria
						}
					}
					else if ($chatdata->checkCommandWithValue($text, $listMoreOrders))
					{
						if ($chatdata->updateChatdata('telegram_conv_state', $chatdata->_listOrdersState))
						{
							$value = $this->getCommandValue($text, $listMoreOrders);
							$showMore = (int)$value; // get where listing stopped
							$moreOrders = true;
						}
					}
					else
						$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState);
				}

				// instances conversation state
				$conv_state = $chatdata->getTelegramConvState();

				// mage helper
				$magehelper = Mage::helper('core');

				if ($supportGroupdId[0] == "g") // remove the 'g' from groupd id, and add '-'
					$supportGroupdId = "-" . ltrim($supportGroupdId, "g");

				// if it's a group message
				if ($telegram->messageFromGroup())
				{
					if ($chatId == $supportGroupdId) // if the group sending the message is the support group
					{
						$reply_msg_id = $telegram->ReplyToMessageID();
						if (!empty($reply_msg_id)) // if the message is replying another message
						{
							$foreignchatdata = Mage::getModel('chatbot/chatdata')->load($reply_msg_id, 'last_support_message_id');
							if (!empty($foreignchatdata->getLastSupportMessageId())) // check if current reply message id is saved on databse
							{
								$api_name = $foreignchatdata->getLastSupportChat();
								if ($api_name == $foreignchatdata->_fbBot)
									Mage::getModel('chatbot/api_facebook_handler')->foreignMessageFromSupport($foreignchatdata->getFacebookChatId(), $text); // send chat id and the original text
							}
							else
							{
								$replyFromUserId = $telegram->ReplyToMessageFromUserID();
								if (!is_null($replyFromUserId))
								{
									$admEndSupport = "/" . $chatdata->_admEndSupportCmd;
									$admBlockSupport = "/" . $chatdata->_admBlockSupportCmd;

									$customerData = Mage::getModel('chatbot/chatdata')->load($replyFromUserId, 'telegram_chat_id');
									if ($text == $admEndSupport)
									{
										$customerData->updateChatdata('telegram_conv_state', $chatdata->_startState);
										$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. The customer is no longer on support.")));
										$telegram->sendMessage(array('chat_id' => $replyFromUserId, 'text' => $magehelper->__("Support ended."))); // TODO
									}
									else if ($text == $admBlockSupport)
									{
										$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("We're working on this feature.")));
									}
									else // if no command, then it's replying the user
									{
										if ($customerData->getTelegramConvState() != $chatdata->_supportState)
										{
											$customerData->updateChatdata('telegram_conv_state', $chatdata->_supportState);
											$telegram->sendMessage(array('chat_id' => $replyFromUserId, 'text' => $magehelper->__("You're now on support mode.")));
										}
										$telegram->sendMessage(array('chat_id' => $replyFromUserId, 'text' => $magehelper->__("Message from support") . ":\n" . $text)); // send message to customer TODO
										$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Message sent."))); // send message to admin group TODO
									}
								}
							}
						}
						else // proccess pure admin commands
						{
							$admSend2All = "/" . $chatdata->_admSendMessage2AllCmd;

							if ($chatdata->checkCommandWithValue($text, $admSend2All))
							{
								$message = trim($chatdata->getCommandValue($text, $admSend2All));
								if (!empty($message))
								{
									$chatbotcollection = Mage::getModel('chatbot/chatdata')->getCollection();
									foreach($chatbotcollection as $chatbot)
									{
										$tgChatId = $chatbot->getTelegramChatId();
										if ($tgChatId)
											$telegram->sendMessage(array('chat_id' => $tgChatId, 'text' => $message)); // $magehelper->__("Message from support") . ":\n" .
									}
									$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Message sent.")));
								}
								else
									$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please use") . ' "' . $admSend2All . " " . $magehelper->__("your message here.") . '"'));
							}
						}
						return $telegram->respondSuccess();
					}
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("I don't work with groups."))); // TODO
					return $telegram->respondSuccess(); // ignore all group messages
				}

				if ($chatdata->getIsLogged() == "1") // check if customer is logged
				{
					if (Mage::getModel('customer/customer')->load((int)$chatdata->getCustomerId())->getId()) // if is a valid customer id
					{
						if ($chatdata->getEnableTelegram() != "1")
						{
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("To talk with me, please enable Telegram on your account chatbot settings.")));
							return $telegram->respondSuccess();
						}
					}
				}

				// init start command
				$chatdata->_startCmd['command'] = "/start";

				if (is_null($chatdata->getTelegramChatId()) && !$chatdata->checkCommandWithValue($text, $chatdata->_startCmd['command'])) // if user isn't registred, and not using the start command
				{
					$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_welcome_msg'); // TODO
					if ($message) // TODO
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
					try
					{
						$hash = substr(md5(uniqid($chatId, true)), 0, 150); // TODO
						$chatdata // using magento model to insert data into database the proper way
						->setTelegramChatId($chatId)
							->setHashKey($hash) // TODO
							->save();
					}
					catch (Exception $e)
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage)); // TODO
					}
					return $telegram->respondSuccess();
				}

				// init other commands (for now, no alias for telegram)
				$chatdata->_listCategoriesCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(1)['command']);
				$chatdata->_searchCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(2)['command']);
				$chatdata->_loginCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(3)['command']);
				$chatdata->_listOrdersCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(4)['command']);
				$chatdata->_reorderCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(5)['command']);
				$chatdata->_add2CartCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(6)['command']);
				$chatdata->_checkoutCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(7)['command']);
				$chatdata->_clearCartCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(8)['command']);
				$chatdata->_trackOrderCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(9)['command']);
				$chatdata->_supportCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(10)['command']);
				$chatdata->_sendEmailCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(11)['command']);
				$chatdata->_cancelCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(12)['command']);
				$chatdata->_helpCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(13)['command']);
				$chatdata->_aboutCmd['command'] = $chatdata->validateTelegramCmd("/" . $chatdata->getCommandString(14)['command']);

				if (!$chatdata->_cancelCmd['command']) $chatdata->_cancelCmd['command'] = "/cancel"; // it must always have a cancel command

				// init messages
				$chatdata->_errorMessage = $magehelper->__("Something went wrong, please try again.");
				$chatdata->_cancelMessage = $magehelper->__("To cancel, send") . " " . $chatdata->_cancelCmd['command'];
				$chatdata->_canceledMessage = $magehelper->__("Ok, canceled.");
				$chatdata->_loginFirstMessage = $magehelper->__("Please login first.");
				array_push($chatdata->_positiveMessages, $magehelper->__("Ok"), $magehelper->__("Okay"), $magehelper->__("Cool"), $magehelper->__("Awesome"));
				// $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)]

				// TODO DEBUG COMMANDS
				//				$temp_var = $chatdata->_startCmd['command'] . " - " .
				//				$chatdata->_listCategoriesCmd['command'] . " - " .
				//				$chatdata->_searchCmd['command'] . " - " .
				//				$chatdata->_loginCmd['command'] . " - " .
				//				$chatdata->_listOrdersCmd['command'] . " - " .
				//				$chatdata->_reorderCmd['command'] . " - " .
				//				$chatdata->_add2CartCmd['command'] . " - " .
				//				$chatdata->_checkoutCmd['command'] . " - " .
				//				$chatdata->_clearCartCmd['command'] . " - " .
				//				$chatdata->_trackOrderCmd['command'] . " - " .
				//				$chatdata->_supportCmd['command'] . " - " .
				//				$chatdata->_sendEmailCmd['command'];
				//				$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $temp_var));
				//				$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $conv_state));

				// start command
				if ($chatdata->checkCommandWithValue($text, $chatdata->_startCmd['command'])) // ignore alias
				//if ($text == $chatdata->_startCmd['command'])
				{
					$startdata = explode(" ", $text);
					if (is_array($startdata) && count($startdata) > 1) // has hash parameter
					{
						$chat_hash = $chatdata->load(trim($startdata[1]), 'hash_key');
						if ($chat_hash->getHashKey())
						{
							try
							{
								$chat_hash->addData(array("telegram_chat_id" => $chatId));
								$chat_hash->save();
							}catch (Exception $e){}
							$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_welcome_msg'); // TODO
							if ($message) // TODO
								$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
						}
					}
					else if ($chatdata->getTelegramChatId()) // TODO
					{
						$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_about_msg'); // TODO
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));

						//						$data = array(
						//							//'customer_id' => $customerId,
						//							'telegram_chat_id' => $chat_id
						//						); // data to be insert on database
						//						$model = Mage::getModel('chatbot/chatdata')->load($chatdata->getId())->addData($data); // insert data on database
						//						$model->setId($chatdata->getId())->save(); // save (duh)
					}
					else // if customer id isnt on our database, means that we need to insert his data
					{
						$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_welcome_msg'); // TODO
						if ($message) // TODO
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
						try
						{
							$hash = substr(md5(uniqid($chatId, true)), 0, 150); // TODO
							Mage::getModel('chatbot/chatdata') // using magento model to insert data into database the proper way
							->setTelegramChatId($chatId)
								->setHashKey($hash) // TODO
								->save();
						}
						catch (Exception $e)
						{
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage)); // TODO
						}
					}
					return $telegram->respondSuccess();
				}

				// help command
				if ($chatdata->checkCommand($text, $chatdata->_helpCmd))
				{
					$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_help_msg'); // TODO
					if ($message) // TODO
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
					return $telegram->respondSuccess();
				}

				// about command
				if ($chatdata->checkCommand($text, $chatdata->_aboutCmd))
				{
					$message = Mage::getStoreConfig('chatbot_enable/telegram_config/telegram_about_msg'); // TODO
					$cmdlisting = Mage::getStoreConfig('chatbot_enable/telegram_config/enable_command_list');
					if ($cmdlisting == 1)
					{
						$message .= "\n\n" . $magehelper->__("Command list") . ":\n";
						if ($chatdata->_listCategoriesCmd['command']) $message .= $chatdata->_listCategoriesCmd['command'] . " - " . $magehelper->__("List store categories.") . "\n";
						if ($chatdata->_searchCmd['command']) $message .= $chatdata->_searchCmd['command'] . " - " . $magehelper->__("Search for products.") . "\n";
						if ($chatdata->_loginCmd['command']) $message .= $chatdata->_loginCmd['command'] . " - " . $magehelper->__("Login into your account.") . "\n";
						if ($chatdata->_listOrdersCmd['command']) $message .= $chatdata->_listOrdersCmd['command'] . " - " . $magehelper->__("List your personal orders.") . "\n";
						//$message .= $chatdata->_reorderCmd['command'] . " - " . $magehelper->__("Reorder a order.") . "\n";
						//$message .= $chatdata->_add2CartCmd['command'] . " - " . $magehelper->__("Add product to cart.") . "\n";
						if ($chatdata->_checkoutCmd['command']) $message .= $chatdata->_checkoutCmd['command'] . " - " . $magehelper->__("Checkout your order.") . "\n";
						if ($chatdata->_clearCartCmd['command']) $message .= $chatdata->_clearCartCmd['command'] . " - " . $magehelper->__("Clear your cart.") . "\n";
						if ($chatdata->_trackOrderCmd['command']) $message .= $chatdata->_trackOrderCmd['command'] . " - " . $magehelper->__("Track your order status.") . "\n";
						if ($chatdata->_supportCmd['command']) $message .= $chatdata->_supportCmd['command'] . " - " . $magehelper->__("Send message to support.") . "\n";
						if ($chatdata->_sendEmailCmd['command']) $message .= $chatdata->_sendEmailCmd['command'] . " - " . $magehelper->__("Send email.") . "\n";
						//$message .= $chatdata->_cancelCmd['command'] . " - " . $magehelper->__("Cancel.");
						if ($chatdata->_helpCmd['command']) $message .= $chatdata->_helpCmd['command'] . " - " . $magehelper->__("Get help.") . "\n";
						//$message .= $chatdata->_aboutCmd['command'] . " - " . $magehelper->__("About.");
					}

					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
					return $telegram->respondSuccess();
				}

				// cancel command
				if ($chatdata->checkCommand($text, $chatdata->_cancelCmd)) // TODO
				{
					if ($conv_state == $chatdata->_listCategoriesState)
					{
						$keyb = $telegram->buildKeyBoardHide(true); // hide keyboard built on listing categories
						$content = array('chat_id' => $chatId, 'reply_markup' => $keyb, 'text' => $chatdata->_canceledMessage);
					}
					else if ($conv_state == $chatdata->_supportState)
					{
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("exiting support mode."));
						//$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $magehelper->__("Done.")));
					}
					else if ($conv_state == $chatdata->_searchState)
					{
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_canceledMessage);
					}
					else if ($conv_state == $chatdata->_sendEmailState)
					{
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_canceledMessage);
					}
					else if ($conv_state == $chatdata->_listProductsState)
					{
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_canceledMessage);
					}
					else if ($conv_state == $chatdata->_listOrdersState)
					{
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_canceledMessage);
					}
					else
						$content = array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage);

					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					else
						$telegram->sendMessage($content);
					return $telegram->respondSuccess();
				}

				// add2cart commands
				if ($chatdata->checkCommandWithValue($text, $chatdata->_add2CartCmd['command'])) // ignore alias
				{
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
					$cmdvalue = $chatdata->getCommandValue($text, $chatdata->_add2CartCmd['command']);
					if ($cmdvalue) // TODO
					{
						if ($chatdata->addProd2Cart($cmdvalue))
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Added. To checkout send") . " " . $chatdata->_checkoutCmd['command']));
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					}
					return $telegram->respondSuccess();
				}

				// states
				if ($conv_state == $chatdata->_listCategoriesState) // TODO show only in stock products
				{
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
					if ($cat_id)
						$_category = Mage::getModel('catalog/category')->load($cat_id);
					else
						$_category = Mage::getModel('catalog/category')->loadByAttribute('name', $text);
					$keyb = $telegram->buildKeyBoardHide(true); // hide keyboard built on listing categories

					$errorFlag = false;
					if ($_category) // check if variable isn't false/empty
					{
						if ($_category->getId()) // check if is a valid category
						{
							$noProductFlag = false;
							$productCollection = $_category->getProductCollection()
								->addAttributeToSelect('*')
								->addAttributeToFilter('visibility', 4)
								->addAttributeToFilter('type_id', 'simple');
							Mage::getSingleton('cataloginventory/stock')->addInStockFilterToCollection($productCollection);
							$productIDs = $productCollection->getAllIds();

							if ($productIDs)
							{
								$i = 0;
								$total = count($productIDs);

								if ($showMore < $total)
								{
									if ($showMore == 0)
									{
										if ($total == 1)
											$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. This category has only one product.", $total)));
										else
											$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. This category has %s products.", $total)));
									}

									foreach ($productIDs as $productID)
									{
										$message = $chatdata->prepareTelegramProdMessages($productID);
										if ($message) // TODO
										{
											if ($i >= $showMore)
											{
												$image = $chatdata->loadImageContent($productID);
												if ($image)
													$telegram->sendPhoto(array('chat_id' => $chatId, 'reply_markup' => $keyb, 'photo' => $image, 'caption' => $message));
												else
													$telegram->sendMessage(array('chat_id' => $chatId, 'reply_markup' => $keyb, 'text' => $message));

												if (($i + 1) != $total && $i >= ($showMore + $listingLimit)) // if isn't the 'last but one' and $i is bigger than listing limit + what was shown last time ($show_more)
												{
													// TODO add option to list more products
													$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("To show more, send") . " " . $listMoreCategories . $_category->getId() . "_" . (string)($i + 1)));
													if ($chatdata->getTelegramConvState() != $chatdata->_listProductsState)
														if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_listProductsState))
															$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
													break;
												}
												else if (($i + 1) == $total) // if it's the last one, back to _startState
													if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
														$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
											}
											$i++;
										}
									}
									if ($i == 0)
										$noProductFlag = true;
								}
								else
									$errorFlag = true;

							}
							else
								$noProductFlag = true;

							if ($noProductFlag)
								$telegram->sendMessage(array('chat_id' => $chatId, 'reply_markup' => $keyb, 'text' => $magehelper->__("Sorry, no products found in this category.")));
						}
						else
							$errorFlag = true;
					}
					else
						$errorFlag = true;

					if ($errorFlag)
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'reply_markup' => $keyb, 'text' => $chatdata->_errorMessage));
						$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState);
					}
					return $telegram->respondSuccess();
				}
				else if ($conv_state == $chatdata->_searchState) // TODO
				{
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
					$errorFlag = false;
					$noProductFlag = false;
					$productIDs = $chatdata->getProductIdsBySearch($text);
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						return $telegram->respondSuccess();
					}
					else if ($productIDs)
					{
						$i = 0;
						$total = count($productIDs);

						if ($showMore < $total)
						{
							if ($showMore == 0)
							{
								if ($total == 1)
									$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. I've found only %s product for your criteria.", $total)));
								else
									$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. I've found %s products for your criteria.", $total)));
							}

							foreach ($productIDs as $productID)
							{
								$message = $chatdata->prepareTelegramProdMessages($productID);
								if ($message) // TODO
								{
									if ($i >= $showMore)
									{
										$image = $chatdata->loadImageContent($productID);
										if ($image)
											$telegram->sendPhoto(array('chat_id' => $chatId, 'photo' => $image, 'caption' => $message));
										else
											$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));

										if (($i + 1) != $total && $i >= ($showMore + $listingLimit)) // if isn't the 'last but one' and $i is bigger than listing limit + what was shown last time ($show_more)
										{
											// TODO add option to list more products
											$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("To show more, send") . " " . $listMoreSearch . str_replace(" ", "_", $text) . "_" . (string)($i + 1)));
											if ($chatdata->getTelegramConvState() != $chatdata->_listProductsState)
												if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_listProductsState))
													$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
											break;
										}
										else if (($i + 1) == $total) // if it's the last one, back to _startState
											if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
												$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
									}
									$i++;
								}
							}
							if ($i == 0)
								$noProductFlag = true;
						}
						else
							$errorFlag = true;
					}
					else
						$noProductFlag = true;

					if ($noProductFlag)
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Sorry, no products found for this criteria.")));

					if ($errorFlag)
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState);
					}

					return $telegram->respondSuccess();
				}
				else if ($conv_state == $chatdata->_supportState)
				{
					if (!empty($supportGroupdId))
					{
						$telegram->forwardMessage(array('chat_id' => $supportGroupdId, 'from_chat_id' => $chatId, 'message_id' => $telegram->MessageID())); // Reply to this message to reply to the customer
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("we have sent your message to support.")));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					return $telegram->respondSuccess();
				}
				else if ($conv_state == $chatdata->_sendEmailState)
				{
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Trying to send the email...")));
					if ($chatdata->sendEmail($text))
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done.")));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Sorry, I wasn't able to send an email this time. Please try again later.")));
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					return $telegram->respondSuccess();
				}
				else if ($conv_state == $chatdata->_trackOrderState)
				{
					$errorFlag = false;
					if ($chatdata->getIsLogged() == "1")
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
						$order = Mage::getModel('sales/order')->loadByIncrementId($text);
						if ($order->getId())
						{
							if ($order->getCustomerId() == $chatdata->getCustomerId()) // not a problem if customer dosen't exist
							{
								$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Your order status is") . " " . $order->getStatus()));
							}
							else
								$errorFlag = true;
						}
						else
							$errorFlag = true;
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_loginFirstMessage));
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					else if ($errorFlag)
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Sorry, we couldn't find any order with this information.")));
					return $telegram->respondSuccess();
				}

				// commands
				if ($chatdata->checkCommand($text, $chatdata->_listCategoriesCmd))
				{
					$categoryHelper = Mage::helper('catalog/category');
					$categories = $categoryHelper->getStoreCategories(); // TODO test with a store without categories
					$i = 0;
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_listCategoriesState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					else if ($categories)
					{
						$option = array();
						foreach ($categories as $_category) // TODO fix buttons max size
						{
							if ($enableEmptyCategoriesListing != "1") // unallow empty categories listing
							{
								$category = Mage::getModel('catalog/category')->load($_category->getId()); // reload category because EAV Entity
								$productIDs = $category->getProductCollection()
									->addAttributeToSelect('*')
									->addAttributeToFilter('visibility', 4)
									->addAttributeToFilter('type_id', 'simple')
									->getAllIds();
							}
							else
								$productIDs = true;
							if (!empty($productIDs)) // category with no products
							{
								$cat_name = $_category->getName();
								array_push($option, $cat_name);
								$i++;
							}
						}

						$keyb = $telegram->buildKeyBoard(array($option));
						$telegram->sendMessage(array('chat_id' => $chatId, 'reply_markup' => $keyb, 'text' => $magehelper->__("Select a category") . ". " . $chatdata->_cancelMessage));
					}
					else if ($i == 0)
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("No categories available at the moment, please try again later.")));
						if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));

					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_checkoutCmd)) // TODO
				{
					$sessionId = null;
					$quoteId = null;
					$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
					if ($chatdata->getIsLogged() == "1")
					{
						if (Mage::getModel('customer/customer')->load((int)$chatdata->getCustomerId())->getId())
						{
							// if user is set as logged, then login using magento singleton
							$customerSession = Mage::getSingleton('customer/session');
							$customerSession->loginById((int)$chatdata->getCustomerId());
							// then set current quote as customer quote
							$customer = Mage::getModel('customer/customer')->load((int)$chatdata->getCustomerId());
							$quote = Mage::getModel('sales/quote')->loadByCustomer($customer);
							// set quote and session ids from logged user
							$quoteId = $quote->getId();
							$sessionId = $customerSession->getEncryptedSessionId();
						}
					}
					if (!($sessionId && $quoteId))
					{
						// set quote and session ids from chatbot class
						$sessionId = $chatdata->getSessionId();
						$quoteId = $chatdata->getQuoteId();
					}
					$emptyCart = true;
					if ($sessionId && $quoteId)
					{
						$cartUrl = Mage::helper('checkout/cart')->getCartUrl();
						if (!isset(parse_url($cartUrl)['SID']))
							$cartUrl .= "?SID=" . $sessionId; // add session id to url

						$cart = Mage::getModel('checkout/cart')->setQuote(Mage::getModel('sales/quote')->loadByIdWithoutStore((int)$quoteId));
						$ordersubtotal = $cart->getQuote()->getSubtotal();
						if ($ordersubtotal > 0)
						{
							$emptyCart = false;
							$message = $magehelper->__("Products on cart") . ":\n";
							foreach ($cart->getQuote()->getItemsCollection() as $item) // TODO
							{
								$message .= $item->getQty() . "x " . $item->getProduct()->getName() . "\n" .
									$magehelper->__("Price") . ": " . Mage::helper('core')->currency($item->getProduct()->getPrice(), true, false) . "\n\n";
							}
							$message .= $magehelper->__("Total") . ": " .
								Mage::helper('core')->currency($ordersubtotal, true, false) . "\n\n" .
								"[" . $magehelper->__("Checkout Here") . "](" . $cartUrl . ")";

							if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_checkoutState))
								$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
							else
								$telegram->sendMessage(array('chat_id' => $chatId, 'parse_mode' => 'Markdown', 'text' => $message));
						}
						else if (!$chatdata->clearCart()) // try to clear cart
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					}
					if ($emptyCart)
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Your cart is empty.")));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_clearCartCmd))
				{
					$errorFlag = false;
					if ($chatdata->clearCart())
					{
						if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_clearCartState))
							$errorFlag = true;
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Cart cleared.")));
					}
					else
						$errorFlag = true;
					if ($errorFlag)
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_searchCmd))
				{
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_searchState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("what do you want to search for?") . ". " . $chatdata->_cancelMessage));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_loginCmd)) // TODO
				{
					if ($chatdata->getIsLogged() != "1") // customer not logged
					{
						$hashlink = Mage::getUrl('chatbot/settings/index/') . "hash" . DS . $chatdata->getHashKey();
						if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_loginState))
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("To login to your account, click this link") . ": " . $hashlink));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("You're already logged.")));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_listOrdersCmd) || $moreOrders) // TODO
				{
					if ($chatdata->getIsLogged() == "1")
					{
						//$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("let me fetch that for you.")));
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
						$ordersIDs = $chatdata->getOrdersIdsFromCustomer();
						if ($ordersIDs)
						{
							$i = 0;
							$total = count($ordersIDs);
							if ($showMore < $total)
							{
								if ($showMore == 0)
								{
									if ($total == 1)
										$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. You've only one order.", $total)));
									else
										$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Done. I've found %s orders.", $total)));
								}

								foreach($ordersIDs as $orderID)
								{
									$message = $chatdata->prepareTelegramOrderMessages($orderID);
									if ($message) // TODO
									{
										if ($i >= $showMore)
										{
											$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $message));
											if (($i + 1) != $total && $i >= ($showMore + $listingLimit)) // if isn't the 'last but one' and $i is bigger than listing limit + what was shown last time ($show_more)
											{
												// TODO add option to list more orders
												$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("To show more, send") . " " . $listMoreOrders . (string)($i + 1)));
												if ($chatdata->getTelegramConvState() != $chatdata->_listOrdersState)
													if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_listOrdersState))
														$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
												break;
											}
											else if (($i + 1) == $total) // if it's the last one, back to _startState
												if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_startState))
													$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
										}
										$i++;
									}
								}
								if ($i == 0)
									$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
//							else if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_listOrdersState))
//								$telegram->sendMessage(array('chat_id' => $chat_id, 'text' => $chatdata->_errorMessage));
							}
						}
						else
						{
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("This account has no orders.")));
							return $telegram->respondSuccess();
						}
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_loginFirstMessage));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommandWithValue($text, $chatdata->_reorderCmd['command'])) // ignore alias TODO
				{
					if ($chatdata->getIsLogged() == "1")
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Please wait while I check that for you.")));
						$errorFlag = false;
						$cmdvalue = $chatdata->getCommandValue($text, $chatdata->_reorderCmd['command']);
						if ($cmdvalue)
						{
							if ($chatdata->clearCart())
							{
								$order = Mage::getModel('sales/order')->load($cmdvalue);
								if ($order->getId())
								{
									foreach($order->getAllVisibleItems() as $item) {
										if (!$chatdata->addProd2Cart($item->getProductId()))
											$errorFlag = true;
									}
								}
								else
									$errorFlag = true;
							}
							else
								$errorFlag = true;
						}
						else
							$errorFlag = true;

						if ($errorFlag)
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						else if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_reorderState))
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						else // success!!
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("to checkout send") . " " . $chatdata->_checkoutCmd['command']));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_loginFirstMessage));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_trackOrderCmd)) // TODO
				{
					if ($chatdata->getIsLogged() == "1")
					{
						$ordersIDs = $chatdata->getOrdersIdsFromCustomer();
						if ($ordersIDs)
						{
							if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_trackOrderState))
								$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
							else
								$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("send the order number.")));
						}
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Your account dosen't have any orders.")));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_loginFirstMessage));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_supportCmd)) // TODO
				{
					if ($chatdata->getFacebookConvState() != $chatdata->_supportState)
					{
						if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_supportState))
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("what do you need support for?") . " " . $chatdata->_cancelMessage));
					}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("You're already on support in other chat application, please close it before opening a new one.")));
					return $telegram->respondSuccess();
				}
				else if ($chatdata->checkCommand($text, $chatdata->_sendEmailCmd)) // TODO
				{
					if (!$chatdata->updateChatdata('telegram_conv_state', $chatdata->_sendEmailState))
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
					else
					{
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_positiveMessages[array_rand($chatdata->_positiveMessages)] . ", " . $magehelper->__("write the email content.")));
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("By doing this you agree that we may contact you directly via chat message.") . " " . $chatdata->_cancelMessage));
					}
					return $telegram->respondSuccess();
				}
				else
				{
					if ($enableFinalMessage2Support == "1")
					{
						if (!empty($supportGroupdId))
						{
//							if ($chatdata->getFacebookConvState() != $chatdata->_supportState) // TODO
//								$chatdata->updateChatdata('telegram_conv_state', $chatdata->_supportState);
							$telegram->forwardMessage(array('chat_id' => $supportGroupdId, 'from_chat_id' => $chatId, 'message_id' => $telegram->MessageID()));
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' =>
								$magehelper->__("Sorry, I didn't understand that.") . " " .
								$magehelper->__("Please wait while our support check your message so you can talk to a real person.")// . " " .
								//$chatdata->_cancelMessage
							)); // TODO
						}
						else
							$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $chatdata->_errorMessage));
						return $telegram->respondSuccess();
					}
					//else if ($enable_witai == "1"){}
					else
						$telegram->sendMessage(array('chat_id' => $chatId, 'text' => $magehelper->__("Sorry, I didn't understand that."))); // TODO
				}
			}

			return $telegram->respondSuccess();
		}
	}


?>