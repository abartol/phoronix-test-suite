<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2009, Phoronix Media
	Copyright (C) 2008 - 2009, Michael Larabel
	pts-functions_modules.php: Functions related to PTS module loading/management.
	Modules are optional add-ons that don't fit the requirements for entrance into pts-core but provide added functionality.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

// PTS Module Return Types
define("PTS_MODULE_UNLOAD", "PTS_MODULE_UNLOAD");
define("PTS_QUIT", "PTS_QUIT");

function pts_module_startup_init()
{
	// Process initially called when PTS starts up
	if(getenv("PTS_IGNORE_MODULES") == false && PTS_MODE == "CLIENT")
	{
		// Enable the toggling of the system screensaver by default.
		// To disable w/o code modification, set HALT_SCREENSAVER=NO environmental variable
		foreach(pts_trim_explode("\n", file_get_contents(STATIC_DIR . "default-modules.txt")) as $default_module)
		{
			pts_attach_module($default_module);
		}

		pts_load_modules();
		pts_module_process("__startup");
		define("PTS_STARTUP_TASK_PERFORMED", true);
		register_shutdown_function("pts_module_process", "__shutdown");
	}
}
function pts_auto_detect_modules()
{
	// Auto detect modules to load
	$module_variables_file = file_get_contents(STATIC_DIR . "module-variables.txt");
	$module_variables = explode("\n", $module_variables_file);

	foreach($module_variables as $module_var)
	{
		$module_var = pts_trim_explode("=", $module_var);

		if(count($module_var) == 2)
		{
			$env_var = $module_var[0];
			$module = $module_var[1];

			if(!pts_module_manager::is_module_attached($module) && ($e = getenv($env_var)) != false && !empty($e))
			{
				pts_attach_module($module);
			}
		}
	}
}
function pts_load_modules()
{
	// Load the modules list

	// Check for modules to auto-load from the configuration file
	$load_modules = pts_read_user_config(P_OPTION_LOAD_MODULES, null);

	if(!empty($load_modules))
	{
		foreach(explode(",", $load_modules) as $module)
		{
			$module_r = explode("=", $module);

			if(count($module_r) == 2)
			{
				pts_set_environment_variable(trim($module_r[0]), trim($module_r[1]));
			}
			else
			{
				pts_attach_module($module);
			}
		}
	}

	// Check for modules to load manually in PTS_MODULES
	if(($load_modules = getenv("PTS_MODULES")) !== false)
	{
		foreach(explode(",", $load_modules) as $module)
		{
			$module = trim($module);

			if(!pts_module_manager::is_module_attached($module))
			{
				pts_attach_module($module);
			}
		}
	}

	// Detect modules to load automatically
	pts_auto_detect_modules();

	// Clean-up modules list
	pts_module_manager::clean_module_list();

	// Reset counter
	pts_module_manager::set_current_module(null);

	// Load the modules
	$module_store_list = array();
	foreach(pts_module_manager::attached_modules() as $module)
	{
		$module_type = pts_module_type($module);
		if($module_type == "PHP")
		{
			eval("\$module_store_vars = " . $module . "::\$module_store_vars;");
		}
		else
		{
			$module_store_vars = array();
		}

		if(is_array($module_store_vars) && count($module_store_vars) > 0)
		{
			foreach($module_store_vars as $store_var)
			{
				if(!in_array($store_var, $module_store_list))
				{
					array_push($module_store_list, $store_var);
				}
			}
		}
	}

	// Should any of the module options be saved to the results?
	foreach($module_store_list as $var)
	{
		$var_value = getenv($var);

		if($var_value != false && !empty($var_value))
		{
			pts_module_manager::var_store_add($var, $var_value);
		}
	}
}
function pts_attach_module($module)
{
	// Attach a module
	$module = trim($module);

	if(pts_module_type($module) == false)
	{
		return false;
	}

	pts_load_module($module);
	pts_module_manager::attach_module($module);

	if(defined("PTS_STARTUP_TASK_PERFORMED"))
	{
		pts_module_process_task($module, "__startup");
	}
}
function pts_load_module($module)
{
	// Load the actual file needed that contains the module
	return pts_module_type($module) == "PHP" && ((is_file(MODULE_LOCAL_DIR . $module . ".php") && include_once(MODULE_LOCAL_DIR . $module . ".php")) || 
	(is_file(MODULE_DIR . $module . ".php") && include_once(MODULE_DIR . $module . ".php")));
}
function pts_module_processes()
{
	return array("__startup", "__pre_option_process", "__pre_install_process", "__pre_test_download", "__interim_test_download", "__post_test_download", "__pre_test_install", "__post_test_install", "__post_install_process", "__pre_run_process", "__pre_test_run", "__interim_test_run", "__post_test_run", "__post_run_process", "__post_option_process", "__shutdown");
}
function pts_module_events()
{
	return array("__event_global_upload");
}
function pts_is_php_module($module)
{
	return is_file(MODULE_DIR . $module . ".php") || is_file(MODULE_LOCAL_DIR . $module . ".php");
}
function pts_module_valid_user_command($module, $command = null)
{
	$valid = false;

	if($command == null && strpos($module, ".") != false)
	{
		$dot_r = explode(".", $module);

		if(count($dot_r) > 1)
		{
			$module = array_shift($dot_r);
			$command = array_pop($dot_r);
		}
	}

	if(pts_module_type($module) == "PHP")
	{
		if(!pts_module_manager::is_module_attached($module))
		{
			pts_attach_module($module);
		}

		$all_options = pts_php_module_call($module, "user_commands");

		$valid = count($all_options) > 0 && ((isset($all_options[$command]) && method_exists($module, $all_options[$command])) || 
			in_array($command, array("help")));
	}

	return $valid;
}
function pts_module_run_user_command($module, $command, $arguments = null)
{
	$all_options = pts_php_module_call($module, "user_commands");
	
	if(isset($all_options[$command]) && method_exists($module, $all_options[$command]))
	{
		pts_php_module_call($module, $all_options[$command], $arguments);
	}
	else
	{
		// Not a valid command, list available options for the module 
		// or help or list_options was called
		$all_options = pts_php_module_call($module, "user_commands");

		echo "\nUser commands for the " . $module . " module:\n\n";

		foreach($all_options as $option)
		{
			echo "- " . $module . "." . $option . "\n";
		}
		echo "\n";
	}
}
function pts_module_call($module, $process, &$object_pass = null)
{
	$module_type = pts_module_type($module);

	if($module_type == "PHP")
	{
		$module_response = pts_php_module_call($module, $process, $object_pass);
	}
	else if($module_type == "SH")
	{
		$module_response = pts_sh_module_call($module, $process);
	}
	else
	{
		$module_response = "";
	}

	return $module_response;
}
function pts_sh_module_call($module, $process)
{
	if(is_file(($module_file = MODULE_DIR . $module . ".sh")) || is_file(($module_file = MODULE_LOCAL_DIR . $module . ".sh")))
	{
		$module_return = trim(shell_exec("sh " . $module_file . " " . $process . " 2>&1"));
	}
	else
	{
		$module_return = null;
	}

	return $module_return;
}
function pts_php_module_call($module, $process, &$object_pass = null)
{
	if(method_exists($module, $process))
	{
		eval("\$module_val = " . $module . "::" . $process . "(\$object_pass);");
	}
	else if(property_exists($module, $process))
	{
		eval("\$module_val = " . $module . "::" . $process . ";");
	}

	return $module_val;
}
function pts_module_process($process, &$object_pass = null)
{
	// Run a module process on all registered modules
	foreach(pts_module_manager::attached_modules() as $module)
	{
		pts_module_process_task($module, $process, $object_pass);
	}
	pts_module_manager::set_current_module(null);
}
function pts_module_process_task($module, $process, &$object_pass = null)
{
	pts_module_manager::set_current_module($module);

	$module_response = pts_module_call($module, $process, $object_pass);

	if(!empty($module_response))
	{
		switch($module_response)
		{
			case PTS_MODULE_UNLOAD:
				// Unload the PTS module
				pts_module_manager::detach_module($module);
				break;
			case PTS_QUIT:
				// Stop the Phoronix Test Suite immediately
				pts_exit();
				break;
		}
	}
}
function pts_module_process_extensions($extensions, &$write_to)
{
	// Process extensions for modules
	if(!empty($extensions))
	{
		$write_to = $extensions;
		$extensions = explode(";", $extensions);

		foreach($extensions as $ev)
		{
			$ev_r = explode("=", $ev);
			pts_set_environment_variable($ev_r[1], $ev_r[2]);
		}

		pts_auto_detect_modules();
	}
}
function pts_is_module($name)
{
	return pts_module_type($name) != false;
}
function pts_module_type($name)
{
	// Determine the code type of a module
	static $cache;

	if(!isset($cache[$name]))
	{
		if(is_file(MODULE_LOCAL_DIR . $name . ".php"))
		{
			$type = "PHP";
		}
		else if(is_file(MODULE_DIR . $name . ".php"))
		{
			$type = "PHP";
		}
		else if(is_file(MODULE_LOCAL_DIR . $name . ".sh"))
		{
			$type = "SH";
		}
		else if(is_file(MODULE_DIR . $name . ".sh"))
		{
			$type = "SH";
		}
		else
		{
			$type = false;
		}

		$cache[$name] = $type;
	}

	return $cache[$name];
}
function pts_available_modules()
{
	$modules = pts_array_merge(glob(MODULE_DIR . "*"), glob(MODULE_LOCAL_DIR . "*"));
	$module_names = array();

	foreach($modules as $module)
	{
		$module = basename($module);

		if(substr($module, -3) == ".sh")
		{
			array_push($module_names, substr($module, 0, -3));
		}
		else if(substr($module, -4) == ".php")
		{
			array_push($module_names, substr($module, 0, -4));
		}
	}

	asort($module_names);

	return $module_names;
}

?>
