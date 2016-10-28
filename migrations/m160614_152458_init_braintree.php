<?php

use yii\db\Migration;

class m160614_152458_init_braintree extends Migration
{
    public function up()
    {
        $tableOptions = null;

        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8 COLLATE utf8_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('Subscription', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'braintreeId' => $this->string()->notNull(),
            'braintreePlan' => $this->string()->notNull(),
            'quantity' => $this->integer()->notNull(),
            'trialEndAt' => $this->timestamp()->null(),
            'endAt' => $this->timestamp()->null(),
            'createdAt' => $this->timestamp()->null(),
            'updatedAt' => $this->timestamp()->null(),
        ], $tableOptions);

        $this->addColumn('User', 'braintreeId', $this->string());
        $this->addColumn('User', 'paypalEmail', $this->string());
        $this->addColumn('User', 'cardBrand', $this->string());
        $this->addColumn('User', 'cardLastFour', $this->string());
        $this->addColumn('User', 'trialEndAt', $this->timestamp()->null());
    }

    public function down()
    {
        $this->dropTable('Subscription');
        $this->dropColumn('User', 'braintreeId');
        $this->dropColumn('User', 'paypalEmail');
        $this->dropColumn('User', 'cardBrand');
        $this->dropColumn('User', 'cardLastFour');
        $this->dropColumn('User', 'trialEndAt');
    }

    /*
    // Use safeUp/safeDown to run migration code within a transaction
    public function safeUp()
    {
    }

    public function safeDown()
    {
    }
    */
}
